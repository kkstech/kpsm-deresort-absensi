<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $employees = self::visibleTo($request->user())
            ->with(['department:id,name', 'position:id,name', 'workLocation:id,name', 'user:id,email,is_active'])
            ->withCount(['devices' => fn (Builder $q) => $q->whereNull('revoked_at')])
            ->when($filters['q'] ?? null, fn (Builder $q, $term) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $employees]);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($request->user()->canSeeEmployee($employee), 403);

        return response()->json(['data' => $employee->load('department:id,name', 'position:id,name', 'workLocation:id,name', 'user:id,email,is_active', 'placements.department:id,name')]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $this->validated($request);
        $account = $request->validate([
            'account_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'account_password' => ['required_with:account_email', 'nullable', 'string', 'min:8', 'max:255'],
        ]);

        $employee = DB::transaction(function () use ($data, $account) {
            if (! empty($account['account_email'])) {
                $data['user_id'] = User::query()->create([
                    'name' => $data['name'],
                    'email' => Str::lower($account['account_email']),
                    'password' => $account['account_password'],
                    'role' => 'employee',
                ])->id;
            }
            $employee = Employee::query()->create($data);
            $employee->placements()->create(['department_id' => $employee->department_id, 'effective_from' => $employee->started_on]);
            Audit::log('employees.created', $employee);

            return $employee;
        });

        return response()->json(['data' => $employee], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $this->validated($request, $employee);

        DB::transaction(function () use ($employee, $data) {
            $before = $employee->attributesToArray();
            $employee->update($data);
            if ($employee->wasChanged('department_id')) {
                $employee->placements()->updateOrCreate(['effective_from' => WorkTime::today()], ['department_id' => $employee->department_id]);
            }
            if ($user = $employee->user) {
                $user->update(['name' => $employee->name, 'is_active' => $employee->status === 'active']);
                if ($employee->status !== 'active') {
                    $this->revokeAll($user);
                }
            }
            Audit::log('employees.updated', $employee, $before);
        });

        return response()->json(['data' => $employee->fresh()]);
    }

    /** Buat akun bila belum ada, atau ganti password akun yang ada. */
    public function account(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $user = $employee->user;
        $data = $request->validate([
            'email' => [$user ? 'nullable' : 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        DB::transaction(function () use ($employee, $user, $data) {
            if ($user) {
                $user->update(array_filter(['email' => isset($data['email']) ? Str::lower($data['email']) : null, 'password' => $data['password']]));
                $user->tokens()->delete();
            } else {
                $user = User::query()->create(['name' => $employee->name, 'email' => Str::lower($data['email']), 'password' => $data['password'], 'role' => 'employee']);
                $employee->update(['user_id' => $user->id]);
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
                $user->update(['is_active' => false]);
                $this->revokeAll($user);
            }
            Audit::log('employees.deactivated', $employee, $before);
        });

        return response()->json(['data' => $employee->fresh()]);
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
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('employees', 'code')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:120'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'status' => ['required', 'in:active,inactive'],
            'started_on' => ['required', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
    }
}
