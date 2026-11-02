<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BulkImportEmployees;
use App\Actions\RefreshOpenPolicySnapshots;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmploymentTerm;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer'],
            'level_id' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:active,inactive'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes'],
        ]);

        $query = self::visibleTo($request->user())
            ->with(['department:id,name', 'position:id,name', 'workLocation:id,name', 'user:id,email,role,is_active'])
            ->with(['level:id,code,name', 'employmentTerms'])
            ->withCount(['devices' => fn (Builder $q) => $q->whereNull('revoked_at')])
            ->when($filters['q'] ?? null, fn (Builder $q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($filters['code'] ?? null, fn (Builder $q, $code) => $q->where('code', 'like', "%{$code}%"))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->when(isset($filters['level_id']) && $filters['level_id'] !== '', function (Builder $q) use ($filters) {
                if ($filters['level_id'] === 'none') {
                    $q->whereNull('level_id');
                } else {
                    $q->where('level_id', (int) $filters['level_id']);
                }
            })
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->orderBy('name');

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all' || (! $request->has('page') && ! $request->has('per_page'));

        $today = WorkTime::today();
        $retirementAge = SiteSetting::query()->value('retirement_age_years');
        $canSeeHrFields = $request->user()->isAdmin();
        $enrich = function (Employee $employee) use ($today, $retirementAge, $canSeeHrFields) {
            $term = $employee->employmentTerms->filter(fn (EmploymentTerm $term) => $term->starts_on->format('Y-m-d') <= $today)->sortByDesc('starts_on')->first();
            $employee->setAttribute('employment', $term ? [
                'type' => $term->type,
                'starts_on' => $term->starts_on->format('Y-m-d'),
                'ends_on' => $term->ends_on?->format('Y-m-d'),
                'alert' => $term->type === 'contract' && $term->ends_on
                    ? ($term->ends_on->lt($today) ? 'overdue' : ($term->ends_on->lte(CarbonImmutable::parse($today)->addDays(7)) ? 'urgent' : ($term->ends_on->lte(CarbonImmutable::parse($today)->addDays(30)) ? 'soon' : null)))
                    : null,
            ] : ['type' => 'unknown', 'starts_on' => null, 'ends_on' => null, 'alert' => null]);
            $this->enrichRetirementData($employee, $today, $retirementAge === null ? null : (int) $retirementAge, $canSeeHrFields);
        };

        if ($isAll) {
            $employees = $query->get();
            $employees->each($enrich);

            return response()->json(['data' => $employees]);
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->each($enrich);

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($request->user()->canSeeEmployee($employee), 403);
        $employee->load('department:id,name', 'position:id,name', 'workLocation:id,name', 'user:id,email,is_active', 'placements.department:id,name');
        $this->enrichRetirementData($employee, WorkTime::today(), SiteSetting::query()->value('retirement_age_years'), $request->user()->isAdmin());

        return response()->json(['data' => $employee]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $this->validated($request);
        $account = $request->validate([
            'account_email' => ['nullable', 'email', 'max:255'],
            'account_password' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = DB::transaction(function () use ($data, $account, $request) {
            $accountEmail = ! empty($account['account_email'])
                ? Str::lower($account['account_email'])
                : (! empty($data['email']) ? Str::lower($data['email']) : null);

            $hasAccountRequest = ! empty($account['account_email']) || ! empty($account['account_password']);

            if ($hasAccountRequest) {
                $targetEmail = $accountEmail ?: Str::lower($data['code']).'@deresort.local';
                $existingUser = User::query()
                    ->where('email', $targetEmail)
                    ->orWhere('username', Str::lower($data['code']))
                    ->first();

                if ($existingUser) {
                    if ($existingUser->employee()->exists()) {
                        throw new BusinessRuleException('ACCOUNT_ALREADY_LINKED', 'Akun dengan username atau email ini sudah terhubung ke karyawan lain.', 422);
                    }
                    if (! empty($account['account_password'])) {
                        $existingUser->update([
                            'password' => $account['account_password'],
                            'username' => Str::lower($data['code']),
                        ]);
                    } else {
                        $existingUser->update(['username' => Str::lower($data['code'])]);
                    }
                    $data['user_id'] = $existingUser->id;
                    $data['email'] = $existingUser->email;
                    if (! empty($existingUser->phone)) {
                        $data['phone'] = $existingUser->phone;
                    }
                } elseif (! empty($account['account_password'])) {
                    $data['user_id'] = User::query()->create([
                        'name' => $data['name'],
                        'email' => $targetEmail,
                        'username' => Str::lower($data['code']),
                        'phone' => $data['phone'] ?? null,
                        'avatar_path' => $data['avatar_path'] ?? null,
                        'password' => $account['account_password'],
                        'role' => 'employee',
                    ])->id;
                }
            }
            $employee = Employee::query()->create($data);
            $employee->placements()->create(['department_id' => $employee->department_id, 'effective_from' => $employee->started_on]);
            $employee->employmentTerms()->create(['type' => 'unknown', 'starts_on' => $employee->started_on]);
            if ($employee->level_id) {
                $employee->levelAssignments()->create([
                    'level_id' => $employee->level_id,
                    'effective_from' => $employee->started_on,
                    'reason' => 'Penetapan level awal karyawan',
                    'created_by' => $request->user()?->id,
                ]);
            }
            Audit::log('employees.created', $employee);

            return $employee;
        });

        $this->enrichRetirementData($employee, WorkTime::today(), SiteSetting::query()->value('retirement_age_years'));

        return response()->json(['data' => $employee], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $this->validated($request, $employee);

        DB::transaction(function () use ($employee, $data, $request) {
            $before = $employee->attributesToArray();
            if ($employee->user && ! empty($employee->user->email)) {
                $data['email'] = $employee->user->email;
            }
            $employee->update($data);
            if ($employee->wasChanged('department_id')) {
                $employee->placements()->updateOrCreate(['effective_from' => WorkTime::today()], ['department_id' => $employee->department_id]);
            }
            if ($employee->wasChanged('level_id')) {
                $today = WorkTime::today();
                if ($employee->level_id) {
                    $previous = $employee->levelAssignments()
                        ->where('effective_from', '<', $today)
                        ->orderByDesc('effective_from')
                        ->first();
                    if ($previous && (! $previous->effective_to || $previous->effective_to >= $today)) {
                        $previous->update(['effective_to' => CarbonImmutable::parse($today)->subDay()->toDateString()]);
                    }
                    $employee->levelAssignments()->updateOrCreate(
                        ['effective_from' => $today],
                        [
                            'level_id' => $employee->level_id,
                            'reason' => 'Pembaruan data karyawan oleh admin',
                            'created_by' => $request->user()?->id,
                        ]
                    );
                }
            }
            if ($user = $employee->user) {
                $userUpdates = ['name' => $employee->name];
                if (array_key_exists('avatar_path', $data)) {
                    $userUpdates['avatar_path'] = $data['avatar_path'];
                }
                if (! empty($employee->code)) {
                    $userUpdates['username'] = Str::lower($employee->code);
                }
                if (! in_array($user->role, User::WEB_ROLES, true)) {
                    $userUpdates['is_active'] = $employee->status === 'active';
                }
                if ($employee->phone) {
                    $userUpdates['phone'] = $employee->phone;
                }
                $user->update($userUpdates);
                if ($employee->status !== 'active' && ! in_array($user->role, User::WEB_ROLES, true)) {
                    $this->revokeAll($user);
                }
            }
            Audit::log('employees.updated', $employee, $before);
        });
        app(RefreshOpenPolicySnapshots::class)->forEmployees([$employee->id]);

        $updated = $employee->fresh(['department:id,name', 'position:id,name', 'workLocation:id,name', 'level:id,code,name', 'user:id,email,role,is_active']);
        $this->enrichRetirementData($updated, WorkTime::today(), SiteSetting::query()->value('retirement_age_years'));

        return response()->json(['data' => $updated]);
    }

    public function unlinkedUsers(): JsonResponse
    {
        Gate::authorize('admin');
        $users = User::query()
            ->whereDoesntHave('employee')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'department_id']);

        return response()->json(['data' => $users]);
    }

    public function unlinkedEmployees(): JsonResponse
    {
        Gate::authorize('admin');
        $employees = Employee::query()
            ->whereNull('user_id')
            ->where('status', 'active')
            ->with(['department:id,name', 'position:id,name'])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'email', 'phone', 'department_id', 'position_id']);

        return response()->json(['data' => $employees]);
    }

    public function linkUser(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()->findOrFail($data['user_id']);

        if ($user->employee()->where('id', '!=', $employee->id)->exists()) {
            throw new BusinessRuleException('USER_ALREADY_LINKED', 'Akun user ini sudah terhubung ke karyawan lain.', 422);
        }

        DB::transaction(function () use ($employee, $user) {
            $before = $employee->attributesToArray();
            $updates = [
                'user_id' => $user->id,
                'email' => $user->email,
            ];
            if (! empty($user->phone)) {
                $updates['phone'] = $user->phone;
            }
            $employee->update($updates);
            $user->update(['username' => Str::lower($employee->code)]);

            Audit::log('employees.user_linked', $employee, $before, ['user_id' => $user->id, 'email' => $user->email]);
        });

        return response()->json(['data' => $employee->fresh('user:id,name,email,role,is_active')]);
    }

    public function unlinkUser(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        if (! $employee->user_id) {
            throw new BusinessRuleException('NOT_LINKED', 'Karyawan ini belum terhubung ke akun user.', 422);
        }

        DB::transaction(function () use ($employee) {
            $before = $employee->attributesToArray();
            $userId = $employee->user_id;
            $employee->update(['user_id' => null]);

            Audit::log('employees.user_unlinked', $employee, $before, ['user_id' => $userId]);
        });

        return response()->json(['data' => $employee->fresh(['department:id,name', 'position:id,name', 'workLocation:id,name', 'level:id,code,name', 'user:id,email,role,is_active'])]);
    }

    /** Buat akun bila belum ada, atau ganti password akun yang ada. */
    public function account(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $user = $employee->user;
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($employee, $user, $data) {
            if ($user) {
                if (! empty($data['email'])) {
                    $targetEmail = Str::lower($data['email']);
                    if (User::query()->where('email', $targetEmail)->where('id', '!=', $user->id)->exists()) {
                        throw new BusinessRuleException('EMAIL_TAKEN', 'Email ini sudah digunakan oleh akun lain.', 422);
                    }
                    $user->update(['email' => $targetEmail]);
                    $employee->update(['email' => $targetEmail]);
                }
                $userUpdates = [
                    'password' => $data['password'],
                    'username' => Str::lower($employee->code),
                ];
                if (! empty($employee->phone)) {
                    $userUpdates['phone'] = $employee->phone;
                }
                $user->update($userUpdates);
                $user->tokens()->delete();
            } else {
                $loginEmail = ! empty($data['email'])
                    ? Str::lower($data['email'])
                    : ($employee->email ? Str::lower($employee->email) : Str::lower($employee->code).'@deresort.local');
                $existingUser = User::query()
                    ->where('email', $loginEmail)
                    ->orWhere('username', Str::lower($employee->code))
                    ->first();
                if ($existingUser) {
                    if ($existingUser->employee()->where('id', '!=', $employee->id)->exists()) {
                        throw new BusinessRuleException('ACCOUNT_ALREADY_LINKED', 'Akun user dengan email atau username ini sudah terhubung ke karyawan lain.', 422);
                    }
                    $existingUserUpdates = [
                        'password' => $data['password'],
                        'username' => Str::lower($employee->code),
                    ];
                    if (! empty($employee->phone) || ! empty($existingUser->phone)) {
                        $existingUserUpdates['phone'] = $employee->phone ?? $existingUser->phone;
                    }
                    $existingUser->update($existingUserUpdates);
                    $employee->update([
                        'user_id' => $existingUser->id,
                        'email' => $existingUser->email,
                    ]);
                    $user = $existingUser;
                } else {
                    $user = User::query()->create([
                        'name' => $employee->name,
                        'email' => $loginEmail,
                        'username' => Str::lower($employee->code),
                        'phone' => $employee->phone,
                        'password' => $data['password'],
                        'role' => 'employee',
                    ]);
                    $employee->update([
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);
                }
            }
            Audit::log('employees.account', $employee, null, ['user_id' => $user->id, 'email' => $user->email]);
        });

        return response()->json(['data' => $employee->fresh('user:id,email,is_active')]);
    }

    public function deactivate(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate(['ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$employee->started_on]]);

        DB::transaction(function () use ($employee, $data) {
            $before = $employee->attributesToArray();
            $employee->update(['status' => 'inactive', 'ended_on' => $data['ended_on'] ?? WorkTime::today()]);
            if ($user = $employee->user) {
                if (! in_array($user->role, User::WEB_ROLES, true)) {
                    $user->update(['is_active' => false]);
                    $this->revokeAll($user);
                }
            }
            Audit::log('employees.deactivated', $employee, $before);
        });

        return response()->json(['data' => $employee->fresh(['department:id,name', 'position:id,name', 'workLocation:id,name', 'level:id,code,name', 'user:id,email,role,is_active'])]);
    }

    public function setEmployment(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'type' => ['required', 'in:permanent,contract'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$employee->started_on],
            'ends_on' => ['required_if:type,contract', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'prohibited_unless:type,contract'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $term = DB::transaction(function () use ($employee, $data, $request) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $existingFuture = $employee->employmentTerms()->where('starts_on', '>', $data['starts_on'])->exists();
            if ($existingFuture) {
                abort(409, 'Sudah ada perubahan status kerja pada tanggal tersebut atau sesudahnya.');
            }

            $existingAtStart = $employee->employmentTerms()
                ->where('starts_on', $data['starts_on'])
                ->lockForUpdate()
                ->first();

            if ($existingAtStart) {
                if ($existingAtStart->type !== 'unknown' || $data['starts_on'] !== $employee->started_on) {
                    abort(409, 'Sudah ada status kerja pada tanggal tersebut.');
                }

                $before = $existingAtStart->attributesToArray();
                $existingAtStart->update([
                    'type' => $data['type'],
                    'ends_on' => $data['type'] === 'contract' ? $data['ends_on'] : null,
                    'reason' => $data['reason'],
                    'created_by' => $request->user()->id,
                ]);
                $employee->update(['ended_on' => $data['type'] === 'contract' ? $data['ends_on'] : null]);
                Audit::log('employment.term_created', $existingAtStart, $before, [...$existingAtStart->attributesToArray(), 'reason' => $data['reason']]);

                return $existingAtStart;
            }

            $current = $employee->employmentTerms()->where('starts_on', '<', $data['starts_on'])->orderByDesc('starts_on')->lockForUpdate()->first();
            $before = $current?->attributesToArray();
            if ($current && (! $current->ends_on || $current->ends_on->format('Y-m-d') >= $data['starts_on'])) {
                $current->update(['ends_on' => CarbonImmutable::parse($data['starts_on'])->subDay()->toDateString()]);
            }
            $term = $employee->employmentTerms()->create([
                'type' => $data['type'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['type'] === 'contract' ? $data['ends_on'] : null,
                'reason' => $data['reason'],
                'created_by' => $request->user()->id,
            ]);
            $employee->update(['ended_on' => $data['type'] === 'contract' ? $data['ends_on'] : null]);
            Audit::log('employment.term_created', $term, $before, [...$term->attributesToArray(), 'reason' => $data['reason']]);

            return $term;
        });

        return response()->json(['data' => $term]);
    }

    public function employmentAlerts(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:1,90']])['days'] ?? 30);
        $today = WorkTime::today();
        $until = CarbonImmutable::parse($today)->addDays($days)->toDateString();
        $terms = EmploymentTerm::query()->with('employee:id,code,name,department_id,status')
            ->where('type', 'contract')->whereNotNull('ends_on')->where('ends_on', '<=', $until)
            ->orderBy('ends_on')->get()
            ->filter(fn (EmploymentTerm $term) => $term->employee && $term->employee->status === 'active')
            ->map(fn (EmploymentTerm $term) => ['employee' => $term->employee, 'starts_on' => $term->starts_on->format('Y-m-d'), 'ends_on' => $term->ends_on->format('Y-m-d'), 'status' => $term->ends_on->lt($today) ? 'overdue' : ($term->ends_on->lte(CarbonImmutable::parse($today)->addDays(7)) ? 'urgent' : 'soon')])
            ->values();

        return response()->json(['data' => $terms]);
    }

    public function downloadImportTemplate(BulkImportEmployees $importer): Response
    {
        Gate::authorize('admin');

        $csv = $importer::templateCsv();

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template_import_karyawan.csv"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function previewImport(Request $request, BulkImportEmployees $importer): JsonResponse
    {
        Gate::authorize('admin');

        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $content = file_get_contents($request->file('file')->getRealPath());
        $result = $importer->preview($content);

        return response()->json($result);
    }

    public function importEmployees(Request $request, BulkImportEmployees $importer): JsonResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.code' => ['required', 'string'],
            'rows.*.name' => ['required', 'string'],
            'rows.*.department' => ['required', 'string'],
            'rows.*.position' => ['required', 'string'],
            'rows.*.started_on' => ['required', 'date_format:Y-m-d'],
            'rows.*.employment_type' => ['required', 'in:permanent,contract'],
            'rows.*.ends_on' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.email' => ['nullable'],
            'rows.*.phone' => ['nullable', 'string'],
            'rows.*.base_salary' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'rows.*.religion' => ['nullable', Rule::in(['islam', 'protestant', 'catholic', 'hindu', 'buddhist', 'confucian', 'other'])],
            'rows.*.place_of_birth' => ['nullable', 'string', 'max:120'],
            'rows.*.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rows.*.gender' => ['nullable', Rule::in(['male', 'female'])],
            'rows.*.marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'rows.*.permanent_address' => ['nullable', 'string', 'max:2000'],
            'rows.*.current_address' => ['nullable', 'string', 'max:2000'],
            'rows.*.level' => ['nullable', 'string'],
            'rows.*.work_location' => ['nullable', 'string'],
            'rows.*.status' => ['required', 'in:active,inactive'],
            'rows.*.make_account' => ['nullable', 'boolean'],
            'rows.*.password' => ['nullable', 'string'],
        ]);

        $result = $importer->import($validated['rows'], $request->user());

        return response()->json([
            'ok' => true,
            'imported_count' => $result['imported_count'],
        ]);
    }

    public function devices(Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $user = $employee->user;
        if (! $user) {
            return response()->json(['data' => []]);
        }

        $devices = $user->devices()
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->get(['id', 'device_uuid', 'name', 'last_seen_at', 'created_at']);

        return response()->json(['data' => $devices]);
    }

    public function revokeDevices(Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        if ($user = $employee->user) {
            DB::transaction(fn () => $this->revokeAll($user));
            Audit::log('employees.devices_revoked', $employee, null, ['user_id' => $user->id]);
        }

        return response()->json(['ok' => true]);
    }

    public static function visibleTo(User $user): Builder
    {
        return Employee::query()->when(! $user->seesAllEmployees(), fn (Builder $q) => $user->isSupervisor() && $user->department_id
            ? $q->where('department_id', $user->department_id)
            : $q->where('user_id', $user->id));
    }

    private function revokeAll(User $user): void
    {
        $user->tokens()->delete();
        $user->devices()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('employees', 'code')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:120'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'level_id' => ['nullable', 'integer', 'exists:employee_levels,id'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'status' => ['required', 'in:active,inactive'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'base_salary' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'religion' => ['nullable', Rule::in(['islam', 'protestant', 'catholic', 'hindu', 'buddhist', 'confucian', 'other'])],
            'place_of_birth' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'permanent_address' => ['nullable', 'string', 'max:2000'],
            'current_address' => ['nullable', 'string', 'max:2000'],
            'avatar' => ['nullable', 'file', 'image', 'max:5120'],
            'started_on' => [$employee ? 'nullable' : 'required', 'date_format:Y-m-d'],
        ];

        $validated = $request->validate($rules);
        unset($validated['avatar']);

        if ($employee && empty($validated['started_on'])) {
            unset($validated['started_on']);
        }

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $ext = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = 'avatar_'.($employee?->user_id ?? $employee?->id ?? time()).'_'.time().'.'.$ext;
            $path = $file->storeAs('avatars', $filename, 'public');
            $validated['avatar_path'] = $path;
            if ($employee?->avatar_path && Storage::disk('public')->exists($employee->avatar_path)) {
                Storage::disk('public')->delete($employee->avatar_path);
            }
        }

        return $validated;
    }

    private function enrichRetirementData(Employee $employee, string $today, ?int $retirementAge, bool $includeHrFields = true): void
    {
        if (! $includeHrFields) {
            return;
        }
        $employee->makeVisible(['base_salary', 'religion', 'place_of_birth', 'date_of_birth', 'gender', 'marital_status', 'permanent_address', 'current_address']);
        $birthDate = $employee->date_of_birth;
        $todayDate = CarbonImmutable::parse($today);
        $retirementDate = $birthDate && $retirementAge
            ? CarbonImmutable::parse($birthDate)->addYears($retirementAge)
            : null;

        $employee->setAttribute('current_age', $birthDate ? CarbonImmutable::parse($birthDate)->diffInYears($todayDate) : null);
        $employee->setAttribute('retirement_date', $retirementDate?->toDateString());
        $employee->setAttribute('retirement_status', $retirementDate
            ? ($retirementDate->lte($todayDate) ? 'reached' : ($retirementDate->lte($todayDate->addYear()) ? 'soon' : null))
            : null);
    }
}
