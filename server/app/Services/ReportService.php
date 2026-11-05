<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Admin\EmployeeController;
use App\Models\AllowanceResult;
use App\Models\AttendancePeriod;
use App\Models\LeaveRequest;
use App\Models\OvertimeOrder;
use App\Models\ShiftAssignment;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function visibleActiveEmployees(User $user): Builder
    {
        return EmployeeController::visibleTo($user)->where('employees.status', 'active');
    }

    public function validatedFilters(Request $request): array
    {
        $today = WorkTime::today();
        $startOfMonth = CarbonImmutable::parse($today)->startOfMonth()->toDateString();

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $data['from'] = $data['from'] ?? $startOfMonth;
        $data['to'] = $data['to'] ?? $today;

        if ($data['to'] < $data['from']) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Tanggal akhir tidak boleh sebelum tanggal awal.', 422);
        }

        $fromCarbon = CarbonImmutable::parse($data['from']);
        $toCarbon = CarbonImmutable::parse($data['to']);
        if ($fromCarbon->diffInDays($toCarbon) > 62) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Rentang tanggal maksimal 62 hari.', 422);
        }

        return $data;
    }

    public function determinePeriodStatus(string $from, string $to): string
    {
        $startMonth = CarbonImmutable::parse($from)->startOfMonth();
        $endMonth = CarbonImmutable::parse($to)->startOfMonth();

        $current = $startMonth;
        while ($current->lte($endMonth)) {
            $monthStr = $current->format('Y-m');
            $isFinalized = AttendancePeriod::query()
                ->where('month', $monthStr)
                ->where(fn (Builder $query) => $query->whereNotNull('finalized_at')->orWhere('status', 'FINALIZED'))
                ->exists();

            if (! $isFinalized) {
                return 'PROVISIONAL';
            }
            $current = $current->addMonth();
        }

        if ($this->pendingReviewQuery($startMonth->toDateString(), $endMonth->endOfMonth()->toDateString())->exists()) {
            return 'PROVISIONAL';
        }

        return 'FINALIZED';
    }

    public function pendingReviewBlockers(string $from, string $to): array
    {
        $query = $this->pendingReviewQuery($from, $to);
        $count = (clone $query)->count();
        $blockers = $query->orderBy('shift_assignments.work_date')
            ->orderBy('employees.name')
            ->limit(50)
            ->get([
                'shift_assignments.work_date',
                'employees.id as employee_id',
                'employees.code as employee_code',
                'employees.name as employee_name',
            ])
            ->map(fn ($row) => [
                'work_date' => $row->work_date,
                'employee' => [
                    'id' => $row->employee_id,
                    'code' => $row->employee_code,
                    'name' => $row->employee_name,
                ],
            ])
            ->all();

        return ['count' => $count, 'blockers' => $blockers];
    }

    private function pendingReviewQuery(string $from, string $to): \Illuminate\Database\Query\Builder
    {
        return DB::table('shift_sessions')
            ->join('shift_assignments', 'shift_assignments.id', '=', 'shift_sessions.shift_assignment_id')
            ->join('employees', 'employees.id', '=', 'shift_assignments.employee_id')
            ->leftJoin('attendance_records', 'attendance_records.shift_session_id', '=', 'shift_sessions.id')
            ->whereNull('shift_sessions.retired_at')
            ->whereBetween('shift_assignments.work_date', [$from, $to])
            ->whereIn('shift_assignments.status', ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'])
            ->where(function ($query) {
                $query->where('attendance_records.needs_review', true)
                    ->orWhere(function ($query) {
                        $query->whereNull('attendance_records.id')
                            ->whereExists(fn ($events) => $events->selectRaw('1')
                                ->from('attendance_events')
                                ->whereColumn('attendance_events.shift_session_id', 'shift_sessions.id')
                                ->where('attendance_events.result', 'REVIEW_REQUIRED'));
                    });
            })
            ->select('shift_assignments.work_date', 'employees.id as employee_id', 'employees.code as employee_code', 'employees.name as employee_name');
    }

    public function getSummary(User $user, array $filters): array
    {
        $employeeQuery = $this->visibleActiveEmployees($user)
            ->with(['department:id,name', 'position:id,name', 'level:id,code,name'])
            ->when(! empty($filters['department_id']), fn (Builder $q) => $q->where('department_id', $filters['department_id']))
            ->when(! empty($filters['employee_id']), fn (Builder $q) => $q->where('id', $filters['employee_id']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $term = trim($filters['q']);
                $q->where(fn (Builder $sub) => $sub->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
            })
            ->orderBy('name');

        $employees = $employeeQuery->get();
        if ($employees->isEmpty()) {
            return [
                'filters' => $filters,
                'period_status' => $this->determinePeriodStatus($filters['from'], $filters['to']),
                'kpi' => $this->emptyKpi(),
                'rows' => [],
            ];
        }

        $employeeIds = $employees->pluck('id')->all();

        $assignments = ShiftAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->whereIn('status', ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'])
            ->with([
                'sessions' => fn ($q) => $q->whereNull('retired_at')->orderBy('seq'),
                'sessions.attendanceRecord.latePermission',
                'sessions.attendanceEvents',
            ])
            ->get()
            ->groupBy('employee_id');

        $overtimes = OvertimeOrder::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->where('status', 'APPROVED')
            ->get()
            ->groupBy('employee_id');

        $leaves = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'APPROVED')
            ->where('work_date', '<=', $filters['to'])
            ->where(function (Builder $q) use ($filters) {
                $q->where(fn (Builder $sub) => $sub->whereNotNull('end_date')->where('end_date', '>=', $filters['from']))
                    ->orWhere(fn (Builder $sub) => $sub->whereNull('end_date')->where('work_date', '>=', $filters['from']));
            })
            ->get()
            ->groupBy('employee_id');

        $allowances = AllowanceResult::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->get()
            ->groupBy('employee_id');

        $rows = [];
        $totalScheduledSessions = 0;
        $totalAttendedDays = 0;
        $totalAttendedSessions = 0;
        $totalVerifiedMinutes = 0;
        $totalOvertimeMinutes = 0;
        $totalMealAllowance = 0;
        $totalTransportAllowance = 0;
        $totalLateCount = 0;
        $totalLateMinutes = 0;
        $totalReviewCount = 0;
        $totalLeaveDays = 0;

        foreach ($employees as $employee) {
            $empAssignments = $assignments->get($employee->id, collect());
            $empSessions = $empAssignments->flatMap->sessions;
            $empOvertimes = $overtimes->get($employee->id, collect());
            $empLeaves = $leaves->get($employee->id, collect());
            $empAllowances = $allowances->get($employee->id, collect());

            $scheduledDays = $empAssignments->pluck('work_date')->unique()->count();
            $scheduledSessions = $empSessions->count();
            $scheduledMinutes = (int) $empSessions->sum(fn (ShiftSession $s) => $s->scheduledMinutes());

            $attendedDates = [];
            $attendedSessionsCount = 0;
            $verifiedMinutes = 0;
            $lateCount = 0;
            $lateMinutes = 0;
            $latePermittedCount = 0;
            $reviewCount = 0;

            foreach ($empSessions as $session) {
                $record = $session->attendanceRecord;
                $events = $session->attendanceEvents;
                $hasCheckIn = $record && $record->check_in_at !== null;
                $hasEvents = $events->isNotEmpty();

                if ($hasCheckIn || in_array($record?->status, ['COMPLETE', 'PRESENT', 'PROVISIONAL'], true)) {
                    $attendedDates[$session->assignment->work_date] = true;
                    $attendedSessionsCount++;
                }

                if ($record) {
                    $verifiedMinutes += (int) $record->verified_minutes;
                    if ($record->is_late) {
                        $lateCount++;
                        $lateMinutes += (int) $record->raw_late_minutes;
                        if ($record->latePermission?->status === 'APPROVED') {
                            $latePermittedCount++;
                        }
                    }
                    if ($record->needs_review) {
                        $reviewCount++;
                    }
                } elseif ($hasEvents && $events->contains('result', 'REVIEW_REQUIRED')) {
                    $reviewCount++;
                }
            }

            $attendedDays = count($attendedDates);
            $lateUnpermittedCount = max(0, $lateCount - $latePermittedCount);
            $empOvertimeMinutes = (int) $empOvertimes->sum('verified_minutes');

            $empLeaveDays = 0;
            foreach ($empLeaves as $leave) {
                $days = (int) ($leave->days_count ?: 1);
                $empLeaveDays += max(1, $days);
            }

            $mealAmount = (int) $empAllowances->where('status', 'ELIGIBLE')->sum('meal_amount');
            $transportAmount = (int) $empAllowances->where('status', 'ELIGIBLE')->sum('transport_amount');
            $totalAllowance = $mealAmount + $transportAmount;

            $totalScheduledSessions += $scheduledSessions;
            $totalAttendedDays += $attendedDays;
            $totalAttendedSessions += $attendedSessionsCount;
            $totalVerifiedMinutes += $verifiedMinutes;
            $totalOvertimeMinutes += $empOvertimeMinutes;
            $totalMealAllowance += $mealAmount;
            $totalTransportAllowance += $transportAmount;
            $totalLateCount += $lateCount;
            $totalLateMinutes += $lateMinutes;
            $totalReviewCount += $reviewCount;
            $totalLeaveDays += $empLeaveDays;

            $rows[] = [
                'employee' => [
                    'id' => $employee->id,
                    'code' => $employee->code,
                    'name' => $employee->name,
                    'department' => $employee->department?->name ?? '-',
                    'position' => $employee->position?->name ?? '-',
                    'level' => $employee->level?->name ?? '-',
                    'avatar_url' => $employee->avatar_url,
                ],
                'scheduled_days' => $scheduledDays,
                'attended_days' => $attendedDays,
                'scheduled_sessions' => $scheduledSessions,
                'attended_sessions' => $attendedSessionsCount,
                'scheduled_minutes' => $scheduledMinutes,
                'verified_minutes' => $verifiedMinutes,
                'verified_hours_formatted' => $this->formatHoursMinutes($verifiedMinutes),
                'late_count' => $lateCount,
                'late_minutes' => $lateMinutes,
                'late_permitted_count' => $latePermittedCount,
                'late_unpermitted_count' => $lateUnpermittedCount,
                'review_count' => $reviewCount,
                'overtime_minutes' => $empOvertimeMinutes,
                'overtime_hours_formatted' => $this->formatHoursMinutes($empOvertimeMinutes),
                'leave_days' => $empLeaveDays,
                'meal_allowance' => $mealAmount,
                'transport_allowance' => $transportAmount,
                'total_allowance' => $totalAllowance,
            ];
        }

        $periodStatus = $this->determinePeriodStatus($filters['from'], $filters['to']);

        $kpi = [
            'total_employees' => count($rows),
            'total_scheduled_sessions' => $totalScheduledSessions,
            'total_attended_days' => $totalAttendedDays,
            'total_attended_sessions' => $totalAttendedSessions,
            'total_verified_minutes' => $totalVerifiedMinutes,
            'total_verified_hours_formatted' => $this->formatHoursMinutes($totalVerifiedMinutes),
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'total_overtime_hours_formatted' => $this->formatHoursMinutes($totalOvertimeMinutes),
            'total_meal_allowance' => $totalMealAllowance,
            'total_transport_allowance' => $totalTransportAllowance,
            'total_allowance_amount' => $totalMealAllowance + $totalTransportAllowance,
            'total_late_count' => $totalLateCount,
            'total_late_minutes' => $totalLateMinutes,
            'total_review_count' => $totalReviewCount,
            'total_leave_days' => $totalLeaveDays,
            'attendance_rate' => $totalScheduledSessions > 0
                ? (int) round(($totalAttendedSessions / $totalScheduledSessions) * 100)
                : 0,
        ];

        return [
            'filters' => $filters,
            'period_status' => $periodStatus,
            'kpi' => $kpi,
            'rows' => $rows,
            'generated_at' => WorkTime::now()->toISOString(),
            'generated_at_local' => WorkTime::local(WorkTime::now())?->format('d/m/Y H:i'),
        ];
    }

    public function getDetails(User $user, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $sessionQuery = ShiftSession::query()
            ->whereNull('retired_at')
            ->whereHas('assignment', function (Builder $q) use ($user, $filters) {
                $q->whereBetween('work_date', [$filters['from'], $filters['to']])
                    ->whereIn('status', ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'])
                    ->whereIn('employee_id', $this->visibleActiveEmployees($user)->select('employees.id'))
                    ->when(! empty($filters['department_id']), fn (Builder $sub) => $sub->whereHas('employee', fn (Builder $e) => $e->where('department_id', $filters['department_id'])))
                    ->when(! empty($filters['employee_id']), fn (Builder $sub) => $sub->where('employee_id', $filters['employee_id']))
                    ->when(! empty($filters['q']), function (Builder $sub) use ($filters) {
                        $term = trim($filters['q']);
                        $sub->whereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
                    });
            })
            ->with([
                'assignment.employee.department:id,name',
                'assignment.employee.position:id,name',
                'attendanceRecord.latePermission',
                'attendanceRecord.overtimeOrder',
                'attendanceEvents',
            ])
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->orderByDesc('start_at')
            ->orderByDesc('id');

        $paginator = $sessionQuery->paginate($perPage);

        // Fetch allowances for the assignments in this page to enrich detail rows
        $assignmentIds = $paginator->getCollection()->pluck('shift_assignment_id')->unique()->all();
        $allowances = AllowanceResult::query()
            ->whereIn('shift_assignment_id', $assignmentIds)
            ->get()
            ->keyBy('shift_assignment_id');

        $paginator->getCollection()->transform(function (ShiftSession $session) use ($allowances) {
            return $this->formatSessionDetailRow($session, $allowances->get($session->shift_assignment_id));
        });

        return $paginator;
    }

    public function getShiftBreakdown(User $user, array $filters): Collection
    {
        return ShiftAssignment::query()
            ->join('employees', 'employees.id', '=', 'shift_assignments.employee_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->leftJoin('shift_categories', 'shift_categories.id', '=', 'shift_assignments.shift_category_id')
            ->whereBetween('shift_assignments.work_date', [$filters['from'], $filters['to']])
            ->whereIn('shift_assignments.status', ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'])
            ->whereIn('employees.id', $this->visibleActiveEmployees($user)->select('employees.id'))
            ->whereHas('sessions', fn (Builder $query) => $query->whereHas('attendanceRecord', fn (Builder $record) => $record->whereNotNull('check_in_at')))
            ->when(! empty($filters['department_id']), fn (Builder $query) => $query->where('employees.department_id', $filters['department_id']))
            ->when(! empty($filters['employee_id']), fn (Builder $query) => $query->where('employees.id', $filters['employee_id']))
            ->when(! empty($filters['q']), function (Builder $query) use ($filters) {
                $term = trim($filters['q']);
                $query->where(fn (Builder $employee) => $employee->where('employees.name', 'like', "%{$term}%")
                    ->orWhere('employees.code', 'like', "%{$term}%"));
            })
            ->select([
                'employees.id as employee_id',
                'employees.code as employee_code',
                'employees.name as employee_name',
                'departments.name as department_name',
                'shift_assignments.shift_category_id',
                'shift_categories.name as category_name',
            ])
            ->selectRaw('COUNT(DISTINCT shift_assignments.id) as shift_count')
            ->groupBy(
                'employees.id',
                'employees.code',
                'employees.name',
                'departments.name',
                'shift_assignments.shift_category_id',
                'shift_categories.name',
            )
            ->orderBy('employees.name')
            ->orderBy('shift_categories.name')
            ->get()
            ->map(fn ($row) => [
                'employee' => [
                    'id' => $row->employee_id,
                    'code' => $row->employee_code,
                    'name' => $row->employee_name,
                    'department' => $row->department_name ?? '-',
                ],
                'category_id' => $row->shift_category_id,
                'category_name' => $row->category_name ?? 'Belum dikategorikan',
                'shift_count' => (int) $row->shift_count,
            ]);
    }

    public function getAllDetailsForExport(User $user, array $filters): Collection
    {
        $sessionQuery = ShiftSession::query()
            ->whereNull('retired_at')
            ->whereHas('assignment', function (Builder $q) use ($user, $filters) {
                $q->whereBetween('work_date', [$filters['from'], $filters['to']])
                    ->whereIn('status', ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'])
                    ->whereIn('employee_id', $this->visibleActiveEmployees($user)->select('employees.id'))
                    ->when(! empty($filters['department_id']), fn (Builder $sub) => $sub->whereHas('employee', fn (Builder $e) => $e->where('department_id', $filters['department_id'])))
                    ->when(! empty($filters['employee_id']), fn (Builder $sub) => $sub->where('employee_id', $filters['employee_id']))
                    ->when(! empty($filters['q']), function (Builder $sub) use ($filters) {
                        $term = trim($filters['q']);
                        $sub->whereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
                    });
            })
            ->with([
                'assignment.employee.department:id,name',
                'assignment.employee.position:id,name',
                'attendanceRecord.latePermission',
                'attendanceRecord.overtimeOrder',
                'attendanceEvents',
            ])
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->orderByDesc('start_at')
            ->orderByDesc('id');

        $sessions = $sessionQuery->get();
        $assignmentIds = $sessions->pluck('shift_assignment_id')->unique()->all();
        $allowances = AllowanceResult::query()
            ->whereIn('shift_assignment_id', $assignmentIds)
            ->get()
            ->keyBy('shift_assignment_id');

        return $sessions->map(fn (ShiftSession $session) => $this->formatSessionDetailRow($session, $allowances->get($session->shift_assignment_id)));
    }

    public function getOvertime(User $user, array $filters): Collection
    {
        $employeeIds = $this->visibleActiveEmployees($user)->select('employees.id');

        return OvertimeOrder::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->when(! empty($filters['department_id']), fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $filters['department_id'])))
            ->when(! empty($filters['employee_id']), fn (Builder $q) => $q->where('employee_id', $filters['employee_id']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $term = trim($filters['q']);
                $q->whereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
            })
            ->with(['employee.department', 'session', 'decisionMaker'])
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->get()
            ->map->toApi();
    }

    public function formatSessionDetailRow(ShiftSession $session, ?AllowanceResult $allowance): array
    {
        $assignment = $session->assignment;
        $employee = $assignment->employee;
        $record = $session->attendanceRecord;
        $events = $session->attendanceEvents;
        $review = $record ? (bool) $record->needs_review : $events->contains('result', 'REVIEW_REQUIRED');
        $now = WorkTime::now();

        $state = match (true) {
            $review => 'NEEDS_REVIEW',
            $record?->is_late && $record?->latePermission?->status === 'APPROVED' => 'PRESENT_LATE_PERMITTED',
            $record?->is_late => 'PRESENT_LATE',
            $record?->check_out_at !== null => 'COMPLETE',
            $record?->check_in_at !== null => 'IN_PROGRESS',
            $now->lt($session->start_at) => 'SCHEDULED',
            $now->lt($session->end_at) => 'NOT_CHECKED_IN',
            default => 'ABSENT',
        };

        $stateLabel = match ($state) {
            'NEEDS_REVIEW' => 'Butuh Review',
            'PRESENT_LATE_PERMITTED' => 'Terlambat (Berizin)',
            'PRESENT_LATE' => 'Terlambat',
            'COMPLETE' => 'Hadir Lengkap',
            'IN_PROGRESS' => 'Sedang Berjalan',
            'SCHEDULED' => 'Terjadwal',
            'NOT_CHECKED_IN' => 'Belum Masuk',
            default => 'Tidak Hadir',
        };

        $checkInClock = $record?->check_in_at ? WorkTime::clock($record->check_in_at, $assignment->work_date) : '-';
        $checkOutClock = $record?->check_out_at
            ? WorkTime::clock($record->check_out_at, $assignment->work_date)
            : ($record?->provisional_out_at ? WorkTime::clock($record->provisional_out_at, $assignment->work_date).' (Provisional)' : '-');

        $scheduleClock = WorkTime::clock($session->start_at, $assignment->work_date).' - '.WorkTime::clock($session->end_at, $assignment->work_date);
        $checkInEvent = ($record?->check_in_event_id ? $events->firstWhere('id', $record->check_in_event_id) : null)
            ?? $events->where('type', 'CHECK_IN')->last();
        $checkOutEvent = ($record?->check_out_event_id ? $events->firstWhere('id', $record->check_out_event_id) : null)
            ?? $events->where('type', 'CHECK_OUT')->last();

        return [
            'id' => $session->id,
            'work_date' => $assignment->work_date,
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'department' => $employee->department?->name ?? '-',
                'position' => $employee->position?->name ?? '-',
                'avatar_url' => $employee->avatar_url,
            ],
            'shift' => [
                'name' => $assignment->template_name,
                'seq' => $session->seq,
                'schedule_clock' => $scheduleClock,
                'scheduled_minutes' => $session->scheduledMinutes(),
            ],
            'attendance' => [
                'state' => $state,
                'state_label' => $stateLabel,
                'check_in_clock' => $checkInClock,
                'check_out_clock' => $checkOutClock,
                'check_in_photo_url' => $record?->check_in_photo_url,
                'check_out_photo_url' => $record?->check_out_photo_url,
                'check_in_latitude' => $checkInEvent?->latitude !== null ? (float) $checkInEvent->latitude : null,
                'check_in_longitude' => $checkInEvent?->longitude !== null ? (float) $checkInEvent->longitude : null,
                'check_in_distance' => $checkInEvent?->distance_meters,
                'check_out_latitude' => $checkOutEvent?->latitude !== null ? (float) $checkOutEvent->latitude : null,
                'check_out_longitude' => $checkOutEvent?->longitude !== null ? (float) $checkOutEvent->longitude : null,
                'check_out_distance' => $checkOutEvent?->distance_meters,
                'is_late' => (bool) $record?->is_late,
                'late_minutes' => (int) ($record?->raw_late_minutes ?? 0),
                'late_permission_status' => $record?->latePermission?->status,
                'needs_review' => $review,
                'review_reason' => $record?->review_reason,
                'verified_minutes' => (int) ($record?->verified_minutes ?? 0),
                'verified_hours_formatted' => $this->formatHoursMinutes((int) ($record?->verified_minutes ?? 0)),
            ],
            'overtime_minutes' => (int) ($record?->overtimeOrder?->verified_minutes ?? 0),
            'allowance' => [
                'status' => $allowance?->status,
                'meal_amount' => (int) ($allowance && $allowance->status === 'ELIGIBLE' ? $allowance->meal_amount : 0),
                'transport_amount' => (int) ($allowance && $allowance->status === 'ELIGIBLE' ? $allowance->transport_amount : 0),
                'total_amount' => (int) ($allowance && $allowance->status === 'ELIGIBLE' ? ($allowance->meal_amount + $allowance->transport_amount) : 0),
            ],
        ];
    }

    public function formatHoursMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0j 0m';
        }
        $hours = intdiv($minutes, 60);
        $remMinutes = $minutes % 60;

        return "{$hours}j {$remMinutes}m";
    }

    private function emptyKpi(): array
    {
        return [
            'total_employees' => 0,
            'total_scheduled_sessions' => 0,
            'total_attended_days' => 0,
            'total_attended_sessions' => 0,
            'total_verified_minutes' => 0,
            'total_verified_hours_formatted' => '0j 0m',
            'total_overtime_minutes' => 0,
            'total_overtime_hours_formatted' => '0j 0m',
            'total_meal_allowance' => 0,
            'total_transport_allowance' => 0,
            'total_allowance_amount' => 0,
            'total_late_count' => 0,
            'total_late_minutes' => 0,
            'total_review_count' => 0,
            'total_leave_days' => 0,
            'attendance_rate' => 0,
        ];
    }
}
