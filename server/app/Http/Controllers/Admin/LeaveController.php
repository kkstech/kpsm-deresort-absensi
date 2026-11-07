<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CalculateLeaveBalance;
use App\Actions\PublishSchedule;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRequest as LeaveRequestModel;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\LeaveYearQuota;
use App\Models\ShiftAssignment;
use App\Models\SiteSetting;
use App\Support\Audit;
use App\Support\WorkflowNotifier;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? substr(WorkTime::today(), 0, 4));
        $item = $this->yearSettings($year);

        return response()->json(['data' => $this->formatYearSettings($item)]);
    }

    public function balances(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? substr(WorkTime::today(), 0, 4));
        $rows = Employee::query()->with('department:id,name')->where('status', 'active')->orderBy('name')->get()
            ->map(fn (Employee $employee) => [
                'employee_id' => $employee->id, 'name' => $employee->name, 'code' => $employee->code,
                'avatar_url' => $employee->avatar_url,
                'department_id' => $employee->department_id,
                'department' => $employee->department?->name,
                ...$this->balanceFor($employee, $year),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quotas' => ['sometimes', 'array', 'min:1'],
            'quotas.*.type' => ['required_with:quotas', 'string', 'distinct', Rule::exists('leave_types', 'code')],
            'quotas.*.quota' => ['required_with:quotas', 'integer', 'between:0,60'],
            'regular_quota' => ['sometimes', 'integer', 'between:0,60'],
            'day_off_quota' => ['sometimes', 'integer', 'between:0,60'],
            'minimum_notice_days' => ['sometimes', 'integer', 'between:0,365'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        if (! isset($data['quotas'])) {
            if (! isset($data['regular_quota'], $data['day_off_quota'])) {
                throw ValidationException::withMessages(['quotas' => 'Kuota per jenis cuti wajib diisi.']);
            }
            $data['quotas'] = [
                ['type' => 'regular', 'quota' => $data['regular_quota']],
                ['type' => 'day_off', 'quota' => $data['day_off_quota']],
            ];
        }
        $year = (int) ($data['year'] ?? substr(WorkTime::today(), 0, 4));
        $item = DB::transaction(function () use ($year, $data) {
            $item = $this->yearSettings($year, true);
            $before = $this->formatYearSettings($item);
            $types = LeaveType::query()->whereIn('code', collect($data['quotas'])->pluck('type'))->get()->keyBy('code');
            foreach ($data['quotas'] as $quotaData) {
                $type = $types->get($quotaData['type']);
                LeaveYearQuota::query()->where('leave_year_id', $item->id)->where('leave_type_id', $type->id)
                    ->lockForUpdate()->firstOrFail()->update(['quota' => $quotaData['quota']]);
            }
            if (array_key_exists('minimum_notice_days', $data)) {
                $siteSetting = SiteSetting::query()->lockForUpdate()->findOrFail(1);
                $beforeSiteSetting = $siteSetting->attributesToArray();
                $siteSetting->update(['leave_minimum_notice_days' => $data['minimum_notice_days']]);
                if ((int) $beforeSiteSetting['leave_minimum_notice_days'] !== (int) $data['minimum_notice_days']) {
                    Audit::log('leave.notice_rule_updated', $siteSetting, $beforeSiteSetting, [...$siteSetting->attributesToArray(), 'reason' => $data['reason']]);
                }
            }
            foreach (Employee::query()->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $employee) {
                $balance = $this->balanceFor($employee, $year);
                foreach ($balance['types'] as $typeBalance) {
                    if ($typeBalance['available'] < 0 || $typeBalance['entitled'] < $typeBalance['used'] + $typeBalance['reserved']) {
                        throw new BusinessRuleException('LEAVE_QUOTA_BELOW_USAGE', 'Kuota tidak dapat diturunkan di bawah cuti yang sudah dipakai atau masih dipesan.', 409, ['employee_id' => $employee->id, 'type' => $typeBalance['code']]);
                    }
                }
            }
            $after = $this->formatYearSettings($item->fresh());
            Audit::log('leave.year_quotas_updated', $item, $before, [...$after, 'reason' => $data['reason']]);

            return $item->fresh();
        });

        return response()->json(['data' => $this->formatYearSettings($item)]);
    }

    public function confirmOpening(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $year = (int) ($data['year'] ?? substr(WorkTime::today(), 0, 4));
        $item = DB::transaction(function () use ($year, $data, $request) {
            $item = $this->yearSettings($year, true);
            if ($item->opening_confirmed_at) {
                return $item;
            }
            $before = $item->attributesToArray();
            $item->update(['opening_confirmed_at' => WorkTime::now(), 'opening_confirmed_by' => $request->user()->id]);
            Audit::log('leave.year_opening_confirmed', $item, $before, [...$item->attributesToArray(), 'reason' => $data['reason']]);

            return $item;
        });

        return response()->json(['data' => $this->formatYearSettings($item)]);
    }

    public function balance(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => null]);
        }
        if ($employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? substr(WorkTime::today(), 0, 4));

        $balance = $this->balanceFor($employee, $year);
        $balance['minimum_notice_days'] = SiteSetting::leaveMinimumNoticeDays();

        return response()->json(['data' => $balance]);
    }

    public function employeeIndex(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }
        if ($employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }
        $requests = LeaveRequestModel::query()->with('leaveType')->where('employee_id', $employee->id)->orderByDesc('work_date')->limit(100)->get();

        return response()->json(['data' => $requests]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'type' => ['required', 'string', Rule::exists('leave_types', 'code')->where('is_active', true)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $startDate = $data['work_date'];
        $endDate = ! empty($data['end_date']) ? $data['end_date'] : $startDate;
        $daysCount = CarbonImmutable::parse($startDate)->diffInDays(CarbonImmutable::parse($endDate)) + 1;

        $item = DB::transaction(function () use ($employee, $data, $startDate, $endDate, $daysCount, $request) {
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $year = (int) substr($startDate, 0, 4);
            $settings = LeaveYear::query()->where('year', $year)->lockForUpdate()->first();
            if (! $settings?->opening_confirmed_at) {
                throw new BusinessRuleException('LEAVE_BALANCE_NOT_OPEN', 'HR belum mengonfirmasi saldo cuti tahun ini.');
            }
            $siteSetting = SiteSetting::current();
            $minimumDate = CarbonImmutable::parse(WorkTime::today())
                ->addDays((int) $siteSetting->leave_minimum_notice_days)
                ->toDateString();
            if ($startDate < $minimumDate) {
                $message = $siteSetting->leave_minimum_notice_days === 0
                    ? 'Tanggal mulai cuti tidak boleh sebelum hari ini.'
                    : "Pengajuan cuti harus dibuat minimal {$siteSetting->leave_minimum_notice_days} hari sebelum tanggal mulai.";
                throw ValidationException::withMessages([
                    'work_date' => $message,
                ]);
            }
            AttendancePeriod::assertOpen($startDate);
            if ($endDate !== $startDate) {
                AttendancePeriod::assertOpen($endDate);
            }
            $this->assertEmploymentCovers($lockedEmployee, $startDate);
            if ($endDate !== $startDate) {
                $this->assertEmploymentCovers($lockedEmployee, $endDate);
            }

            $conflict = LeaveRequestModel::query()
                ->where('employee_id', $lockedEmployee->id)
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->where('work_date', '<=', $endDate)
                ->whereRaw('COALESCE(end_date, work_date) >= ?', [$startDate])
                ->exists();
            if ($conflict) {
                throw new BusinessRuleException('LEAVE_ALREADY_REQUESTED', 'Sudah ada pengajuan cuti aktif pada rentang tanggal ini.');
            }

            $balance = $this->balanceFor($lockedEmployee, $year);
            $typeBalance = $this->balanceForType($balance, $data['type']);
            if ($typeBalance['available'] < $daysCount) {
                throw new BusinessRuleException('LEAVE_BALANCE_INSUFFICIENT', "Sisa kuota {$typeBalance['name']} ({$typeBalance['available']}) tidak mencukupi untuk {$daysCount} hari cuti.");
            }

            $assignment = ShiftAssignment::query()
                ->where('employee_id', $lockedEmployee->id)
                ->whereBetween('work_date', [$startDate, $endDate])
                ->where('status', '!=', 'CANCELLED')
                ->first();

            $item = LeaveRequestModel::query()->create([
                'employee_id' => $lockedEmployee->id,
                'work_date' => $startDate,
                'end_date' => $endDate !== $startDate ? $endDate : null,
                'days_count' => $daysCount,
                'type' => $data['type'],
                'status' => 'PENDING',
                'approval_tier' => $lockedEmployee->approvalTierFor($startDate),
                'reason' => $data['reason'],
                'requested_by' => $request->user()->id,
                'shift_assignment_id' => $assignment?->id,
            ]);
            Audit::log('leave.requested', $item);

            return $item;
        });

        $item->load('leaveType');
        app(WorkflowNotifier::class)->leaveRequested($item);

        return response()->json(['data' => $item], 201);
    }

    public function cancelEmployee(Request $request, LeaveRequestModel $leave): JsonResponse
    {
        $employee = $this->employee($request);
        $leave = DB::transaction(function () use ($employee, $leave) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $item = LeaveRequestModel::query()->lockForUpdate()->findOrFail($leave->id);
            if ((int) $item->employee_id !== (int) $employee->id || $item->status !== 'PENDING') {
                throw new BusinessRuleException('FORBIDDEN', 'Pengajuan ini tidak dapat dibatalkan.', 403);
            }
            AttendancePeriod::assertOpen($item->work_date);
            if ($item->end_date && $item->end_date !== $item->work_date) {
                AttendancePeriod::assertOpen($item->end_date);
            }
            $before = $item->attributesToArray();
            $item->update(['status' => 'CANCELLED', 'cancelled_at' => WorkTime::now()]);
            Audit::log('leave.request_cancelled_by_employee', $item, $before);

            return $item;
        });

        return response()->json(['data' => $leave->fresh()->load('leaveType')]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $filters = $request->validate([
            'status' => ['nullable', 'in:PENDING,APPROVED,REJECTED,CANCELLED'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes'],
        ]);
        $query = LeaveRequestModel::query()->with('leaveType', 'employee:id,code,name,department_id,avatar_path', 'employee.department:id,name', 'assignment:id,status,template_name')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereRaw('COALESCE(end_date, work_date) >= ?', [$from]))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('work_date', '<=', $to))
            ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where(function ($query) {
                $query->where('status', '!=', 'PENDING')->orWhere('approval_tier', 'admin');
            }))
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")->orderBy('work_date');

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all' || (! $request->has('page') && ! $request->has('per_page'));
        if ($isAll) {
            return response()->json(['data' => $query->get()]);
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage);

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

    public function decide(Request $request, LeaveRequestModel $leave): JsonResponse
    {
        if (! $request->user()->canApproveTier($leave->approval_tier)) {
            throw new BusinessRuleException('FORBIDDEN', 'Anda tidak berwenang menyetujui pengajuan cuti pada level karyawan ini.', 403);
        }
        if ((int) $request->user()->id === (int) $leave->requested_by) {
            throw new BusinessRuleException('SELF_APPROVAL', 'Pengaju tidak dapat menyetujui pengajuan cuti sendiri.', 403);
        }
        $data = $request->validate(['decision' => ['required', 'in:APPROVED,REJECTED'], 'decision_reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $item = DB::transaction(function () use ($request, $leave, $data) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($leave->employee_id);
            $item = LeaveRequestModel::query()->lockForUpdate()->findOrFail($leave->id);
            if (! $request->user()->canApproveTier($item->approval_tier)) {
                throw new BusinessRuleException('FORBIDDEN', 'Anda tidak berwenang menyetujui pengajuan cuti pada level karyawan ini.', 403);
            }
            if ((int) $request->user()->id === (int) $item->requested_by) {
                throw new BusinessRuleException('SELF_APPROVAL', 'Pengaju tidak dapat menyetujui pengajuan cuti sendiri.', 403);
            }
            if ($item->status !== 'PENDING') {
                throw new BusinessRuleException('STATE_CONFLICT', 'Pengajuan sudah diputuskan.');
            }

            $startDate = $item->work_date;
            $endDate = $item->end_date ?: $item->work_date;

            AttendancePeriod::assertOpen($startDate);
            if ($endDate !== $startDate) {
                AttendancePeriod::assertOpen($endDate);
            }

            if ($data['decision'] === 'APPROVED') {
                $this->assertEmploymentCovers($employee, $startDate);
                if ($endDate !== $startDate) {
                    $this->assertEmploymentCovers($employee, $endDate);
                }

                $assignments = ShiftAssignment::query()->with('sessions')
                    ->where('employee_id', $employee->id)
                    ->whereBetween('work_date', [$startDate, $endDate])
                    ->where('status', '!=', 'CANCELLED')
                    ->lockForUpdate()
                    ->get();

                foreach ($assignments as $assignment) {
                    $sessionIds = $assignment->sessions->pluck('id');
                    if (AttendanceRecord::query()->whereIn('shift_session_id', $sessionIds)->exists()
                        || DB::table('attendance_events')->whereIn('shift_session_id', $sessionIds)->exists()) {
                        throw new BusinessRuleException('ATTENDANCE_EXISTS', "Sudah ada absensi pada tanggal {$assignment->work_date}. HR tidak dapat mengubahnya menjadi cuti.");
                    }
                }

                foreach ($assignments as $assignment) {
                    if ($assignment->sessions->first()?->start_at->gt(WorkTime::now())) {
                        app(PublishSchedule::class)->cancel($assignment);
                    } else {
                        $beforeSchedule = $assignment->attributesToArray();
                        $assignment->update(['status' => 'CANCELLED', 'revision' => $assignment->revision + 1]);
                        Audit::log('schedule.cancelled_for_leave', $assignment, $beforeSchedule, ['leave_request_id' => $item->id]);
                    }
                }

                if ($assignments->isNotEmpty()) {
                    $item->shift_assignment_id = $assignments->first()->id;
                }

                $item->fill(['status' => 'APPROVED', 'decided_by' => $request->user()->id, 'decision_reason' => $data['decision_reason'], 'approved_at' => WorkTime::now()]);
            } else {
                $item->fill(['status' => 'REJECTED', 'decided_by' => $request->user()->id, 'decision_reason' => $data['decision_reason']]);
            }
            $before = $item->getOriginal();
            $item->save();
            Audit::log('leave.request_decided', $item, $before, [...$item->attributesToArray(), 'decision_reason' => $data['decision_reason']]);

            return $item;
        });

        app(WorkflowNotifier::class)->leaveDecision($item);

        return response()->json(['data' => $item->fresh()->load('leaveType')]);
    }

    public function direct(Request $request): JsonResponse
    {
        Gate::authorize('super-admin');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'type' => ['required', 'string', Rule::exists('leave_types', 'code')->where('is_active', true)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $startDate = $data['work_date'];
        $endDate = ! empty($data['end_date']) ? $data['end_date'] : $startDate;
        $daysCount = CarbonImmutable::parse($startDate)->diffInDays(CarbonImmutable::parse($endDate)) + 1;

        $item = DB::transaction(function () use ($request, $data, $startDate, $endDate, $daysCount) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($data['employee_id']);
            AttendancePeriod::assertOpen($startDate);
            if ($endDate !== $startDate) {
                AttendancePeriod::assertOpen($endDate);
            }
            $year = (int) substr($startDate, 0, 4);
            if (! LeaveYear::query()->where('year', $year)->value('opening_confirmed_at')) {
                throw new BusinessRuleException('LEAVE_BALANCE_NOT_OPEN', 'HR belum mengonfirmasi saldo cuti tahun ini.');
            }
            $this->assertEmploymentCovers($employee, $startDate);
            if ($endDate !== $startDate) {
                $this->assertEmploymentCovers($employee, $endDate);
            }

            $conflict = LeaveRequestModel::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->where('work_date', '<=', $endDate)
                ->whereRaw('COALESCE(end_date, work_date) >= ?', [$startDate])
                ->exists();
            if ($conflict) {
                throw new BusinessRuleException('LEAVE_ALREADY_REQUESTED', 'Sudah ada pengajuan cuti aktif pada rentang tanggal ini.');
            }

            $typeBalance = $this->balanceForType($this->balanceFor($employee, $year), $data['type']);
            if ($typeBalance['available'] < $daysCount) {
                throw new BusinessRuleException('LEAVE_BALANCE_INSUFFICIENT', "Sisa kuota {$typeBalance['name']} tidak mencukupi untuk {$daysCount} hari cuti.");
            }

            $assignments = ShiftAssignment::query()->with('sessions')->where('employee_id', $employee->id)
                ->whereBetween('work_date', [$startDate, $endDate])->where('status', '!=', 'CANCELLED')->lockForUpdate()->get();

            foreach ($assignments as $assignment) {
                $sessionIds = $assignment->sessions->pluck('id');
                if (AttendanceRecord::query()->whereIn('shift_session_id', $sessionIds)->exists()
                    || DB::table('attendance_events')->whereIn('shift_session_id', $sessionIds)->exists()) {
                    throw new BusinessRuleException('ATTENDANCE_EXISTS', "Sudah ada absensi pada tanggal {$assignment->work_date}. HR tidak dapat mengubahnya menjadi cuti.");
                }
            }

            $item = LeaveRequestModel::query()->create([
                'employee_id' => $employee->id,
                'work_date' => $startDate,
                'end_date' => $endDate !== $startDate ? $endDate : null,
                'days_count' => $daysCount,
                'type' => $data['type'],
                'status' => 'APPROVED',
                'reason' => $data['reason'],
                'decision_reason' => $data['reason'],
                'requested_by' => $request->user()->id,
                'direct_by' => $request->user()->id,
                'decided_by' => $request->user()->id,
                'approved_at' => WorkTime::now(),
                'shift_assignment_id' => $assignments->first()?->id,
            ]);

            foreach ($assignments as $assignment) {
                if ($assignment->sessions->first()?->start_at->gt(WorkTime::now())) {
                    app(PublishSchedule::class)->cancel($assignment);
                } else {
                    $before = $assignment->attributesToArray();
                    $assignment->update(['status' => 'CANCELLED', 'revision' => $assignment->revision + 1]);
                    Audit::log('schedule.cancelled_for_leave', $assignment, $before, ['leave_request_id' => $item->id]);
                }
            }
            Audit::log('leave.direct_recorded', $item, null, [...$item->attributesToArray(), 'reason' => $data['reason']]);

            return $item;
        });

        $item->load('leaveType');
        app(WorkflowNotifier::class)->leaveDecision($item);

        return response()->json(['data' => $item], 201);
    }

    public function cancelApproved(Request $request, LeaveRequestModel $leave): JsonResponse
    {
        Gate::authorize('super-admin');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $leave = DB::transaction(function () use ($leave, $data, $request) {
            Employee::query()->lockForUpdate()->findOrFail($leave->employee_id);
            $item = LeaveRequestModel::query()->lockForUpdate()->findOrFail($leave->id);
            if ($item->status !== 'APPROVED') {
                throw new BusinessRuleException('STATE_CONFLICT', 'Hanya cuti yang disetujui yang dapat dibatalkan HR.');
            }
            AttendancePeriod::assertOpen($item->work_date);
            if ($item->end_date && $item->end_date !== $item->work_date) {
                AttendancePeriod::assertOpen($item->end_date);
            }
            $before = $item->attributesToArray();
            $item->update(['status' => 'CANCELLED', 'cancelled_at' => WorkTime::now(), 'decision_reason' => $data['reason'], 'decided_by' => $request->user()->id]);
            Audit::log('leave.approved_cancelled_by_hr', $item, $before, [...$item->attributesToArray(), 'refund' => $item->days_count, 'reason' => $data['reason']]);

            return $item;
        });

        $leave->load('leaveType');
        app(WorkflowNotifier::class)->leaveCancelled($leave);

        return response()->json(['data' => $leave->fresh()->load('leaveType')]);
    }

    public function adjust(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'type' => ['required', 'string', Rule::exists('leave_types', 'code')], 'delta' => ['required', 'integer', 'between:-60,60', 'not_in:0'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $item = DB::transaction(function () use ($employee, $data, $request) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $current = $this->balanceFor($employee, $data['year']);
            $available = $this->balanceForType($current, $data['type'])['available'] + $data['delta'];
            if ($available < 0) {
                throw new BusinessRuleException('LEAVE_QUOTA_BELOW_USAGE', 'Kuota baru lebih kecil dari cuti yang telah dipakai dan dipesan.');
            }
            $item = LeaveAdjustment::query()->create([...$data, 'employee_id' => $employee->id, 'created_by' => $request->user()->id]);
            Audit::log('leave.quota_adjusted', $item, null, [...$item->attributesToArray(), 'reason' => $data['reason']]);

            return $item;
        });

        return response()->json(['data' => $item], 201);
    }

    private function balanceFor(Employee $employee, int $year): array
    {
        return app(CalculateLeaveBalance::class)->for($employee, $year);
    }

    private function balanceForType(array $balance, string $code): array
    {
        return collect($balance['types'])->firstWhere('code', $code)
            ?? ['code' => $code, 'name' => $code, 'entitled' => 0, 'used' => 0, 'reserved' => 0, 'available' => 0];
    }

    private function yearSettings(int $year, bool $lock = false): LeaveYear
    {
        $query = LeaveYear::query()->where('year', $year);
        if ($lock) {
            $query->lockForUpdate();
        }
        $item = $query->firstOrCreate(['year' => $year]);
        $item->ensureTypeQuotas();

        return $item->load('quotas.leaveType');
    }

    private function formatYearSettings(LeaveYear $item): array
    {
        $item->loadMissing('quotas.leaveType');
        $quotas = $item->quotas->map(fn (LeaveYearQuota $quota) => [
            'type' => $quota->leaveType->code,
            'name' => $quota->leaveType->name,
            'is_active' => (bool) $quota->leaveType->is_active,
            'default_quota' => (int) $quota->leaveType->default_quota,
            'quota' => (int) $quota->quota,
        ])->values()->all();
        $quotaByType = collect($quotas)->keyBy('type');
        $data = $item->attributesToArray();
        $data['quotas'] = $quotas;
        $data['regular_quota'] = (int) ($quotaByType->get('regular')['quota'] ?? 0);
        $data['day_off_quota'] = (int) ($quotaByType->get('day_off')['quota'] ?? 0);
        $data['minimum_notice_days'] = SiteSetting::leaveMinimumNoticeDays();

        return $data;
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        if (! $employee || $employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }

        return $employee;
    }

    private function assertEmploymentCovers(Employee $employee, string $date): void
    {
        if ($date < $employee->started_on || ($employee->ended_on && $date > $employee->ended_on)) {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Tanggal berada di luar masa kerja karyawan.');
        }
        $term = $employee->employmentTerms()->where('starts_on', '<=', $date)->orderByDesc('starts_on')->first();
        if ($term && $term->type === 'contract' && $term->ends_on && $date > $term->ends_on->format('Y-m-d')) {
            throw new BusinessRuleException('EMPLOYEE_CONTRACT_EXPIRED', 'Kontrak tidak mencakup tanggal tersebut.');
        }
    }
}
