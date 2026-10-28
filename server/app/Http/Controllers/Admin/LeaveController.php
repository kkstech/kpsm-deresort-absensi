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
use App\Models\LeaveYear;
use App\Models\ShiftAssignment;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LeaveController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? substr(WorkTime::today(), 0, 4));
        $item = LeaveYear::query()->firstOrCreate(['year' => $year], ['regular_quota' => 12, 'day_off_quota' => 4]);

        return response()->json(['data' => $item]);
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
            'regular_quota' => ['required', 'integer', 'between:0,60'],
            'day_off_quota' => ['required', 'integer', 'between:0,60'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $year = (int) ($data['year'] ?? substr(WorkTime::today(), 0, 4));
        $item = DB::transaction(function () use ($year, $data) {
            $item = LeaveYear::query()->where('year', $year)->lockForUpdate()->firstOrCreate(['year' => $year], ['regular_quota' => 12, 'day_off_quota' => 4]);
            $before = $item->attributesToArray();
            $item->update(['regular_quota' => $data['regular_quota'], 'day_off_quota' => $data['day_off_quota']]);
            foreach (Employee::query()->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $employee) {
                $balance = $this->balanceFor($employee, $year);
                foreach (['regular', 'day_off'] as $type) {
                    if ($balance[$type]['available'] < 0 || $balance[$type]['used'] + $balance[$type]['reserved'] > $balance[$type]['entitled']) {
                        throw new BusinessRuleException('LEAVE_QUOTA_BELOW_USAGE', 'Kuota tidak dapat diturunkan di bawah cuti yang sudah dipakai atau masih dipesan.', 409, ['employee_id' => $employee->id, 'type' => $type]);
                    }
                }
            }
            Audit::log('leave.year_quotas_updated', $item, $before, [...$item->attributesToArray(), 'reason' => $data['reason']]);

            return $item;
        });

        return response()->json(['data' => $item]);
    }

    public function confirmOpening(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $year = (int) ($data['year'] ?? substr(WorkTime::today(), 0, 4));
        $item = DB::transaction(function () use ($year, $data, $request) {
            $item = LeaveYear::query()->where('year', $year)->lockForUpdate()->firstOrCreate(['year' => $year], ['regular_quota' => 12, 'day_off_quota' => 4]);
            if ($item->opening_confirmed_at) {
                return $item;
            }
            $before = $item->attributesToArray();
            $item->update(['opening_confirmed_at' => WorkTime::now(), 'opening_confirmed_by' => $request->user()->id]);
            Audit::log('leave.year_opening_confirmed', $item, $before, [...$item->attributesToArray(), 'reason' => $data['reason']]);

            return $item;
        });

        return response()->json(['data' => $item]);
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

        return response()->json(['data' => $this->balanceFor($employee, $year)]);
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
        $requests = LeaveRequestModel::query()->where('employee_id', $employee->id)->orderByDesc('work_date')->limit(100)->get();

        return response()->json(['data' => $requests]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d', 'after:'.WorkTime::today()],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'type' => ['required', 'in:regular,day_off'],
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
            if (($balance[$data['type']]['available'] ?? 0) < $daysCount) {
                throw new BusinessRuleException('LEAVE_BALANCE_INSUFFICIENT', "Sisa kuota cuti ini ({$balance[$data['type']]['available']}) tidak mencukupi untuk {$daysCount} hari cuti.");
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
                'reason' => $data['reason'],
                'requested_by' => $request->user()->id,
                'shift_assignment_id' => $assignment?->id,
            ]);
            Audit::log('leave.requested', $item);

            return $item;
        });

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

        return response()->json(['data' => $leave->fresh()]);
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
        $query = LeaveRequestModel::query()->with('employee:id,code,name,department_id,avatar_path', 'employee.department:id,name', 'assignment:id,status,template_name')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereRaw('COALESCE(end_date, work_date) >= ?', [$from]))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('work_date', '<=', $to))
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
        Gate::authorize('admin');
        $data = $request->validate(['decision' => ['required', 'in:APPROVED,REJECTED'], 'decision_reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $item = DB::transaction(function () use ($request, $leave, $data) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($leave->employee_id);
            $item = LeaveRequestModel::query()->lockForUpdate()->findOrFail($leave->id);
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

        return response()->json(['data' => $item->fresh()]);
    }

    public function direct(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'type' => ['required', 'in:regular,day_off'],
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

            if (($this->balanceFor($employee, $year)[$data['type']]['available'] ?? 0) < $daysCount) {
                throw new BusinessRuleException('LEAVE_BALANCE_INSUFFICIENT', "Sisa kuota cuti ini tidak mencukupi untuk {$daysCount} hari cuti.");
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

        return response()->json(['data' => $item], 201);
    }

    public function cancelApproved(Request $request, LeaveRequestModel $leave): JsonResponse
    {
        Gate::authorize('admin');
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

        return response()->json(['data' => $leave->fresh()]);
    }

    public function adjust(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'type' => ['required', 'in:regular,day_off'], 'delta' => ['required', 'integer', 'between:-60,60', 'not_in:0'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $item = DB::transaction(function () use ($employee, $data, $request) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $current = $this->balanceFor($employee, $data['year']);
            $available = $current[$data['type']]['available'] + $data['delta'];
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
