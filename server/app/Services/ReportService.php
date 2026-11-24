<?php

namespace App\Services;

use App\Actions\CalculateLeaveBalance;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Admin\EmployeeController;
use App\Models\AllowancePolicy;
use App\Models\AllowanceResult;
use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\EmployeeAllowanceOverride;
use App\Models\EmployeeLevelAssignment;
use App\Models\LeaveRequest;
use App\Models\OvertimeOrder;
use App\Models\ShiftAssignment;
use App\Models\ShiftExchangeRequest;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public const NO_DEPARTMENT_LABEL = 'Tanpa Departemen';

    private const MONTHS_ID = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

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
                'groups' => [],
                'period_label' => $this->formatPeriodLabel($filters['from'], $filters['to']),
                'generated_at_local' => WorkTime::local(WorkTime::now())?->format('d/m/Y H:i'),
            ];
        }

        $employeeIds = $employees->pluck('id')->all();
        $transportRates = $this->transportRatesFor($employees, $filters['to']);

        $assignments = ShiftAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->whereIn('status', ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'])
            ->with([
                'category',
                'sessions' => fn ($q) => $q->whereNull('retired_at')->orderBy('seq'),
                'sessions.attendanceRecord.latePermission',
                'sessions.attendanceEvents',
            ])
            ->get()
            ->groupBy('employee_id');

        $approvedExchanges = ShiftExchangeRequest::query()
            ->where('status', 'APPROVED')
            ->where(function ($q) use ($employeeIds) {
                $q->whereIn('employee_id', $employeeIds)
                    ->orWhereIn('target_employee_id', $employeeIds);
            })
            ->get();

        $exchangedAssignmentIds = $approvedExchanges->pluck('shift_assignment_id')
            ->concat($approvedExchanges->pluck('target_assignment_id'))
            ->filter()
            ->unique();

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
        $totalScheduledDays = 0;
        $totalScheduledSessions = 0;
        $totalAttendedDays = 0;
        $totalAttendedSessions = 0;
        $totalVerifiedMinutes = 0;
        $totalActualOvertimeMinutes = 0;
        $totalOvertimeMinutes = 0;
        $totalMealAllowance = 0;
        $totalTransportAllowance = 0;
        $totalLateCount = 0;
        $totalLateMinutes = 0;
        $totalReviewCount = 0;
        $totalLeaveDays = 0;
        $totalCutiDays = 0;
        $totalDepositOffDays = 0;
        $totalMiddle = 0;
        $totalShift1 = 0;
        $totalShift2 = 0;
        $totalShift3 = 0;
        $totalSplit = 0;
        $totalExchange = 0;

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

            $shiftCounts = [
                'shift_1' => 0,
                'shift_2' => 0,
                'shift_3' => 0,
                'middle' => 0,
                'split' => 0,
                'exchange' => 0,
                'total_shifts' => 0,
            ];

            $attendedAssignments = $empAssignments->filter(function (ShiftAssignment $assignment) {
                return $assignment->sessions->contains(function (ShiftSession $session) {
                    $record = $session->attendanceRecord;

                    return ($record && $record->check_in_at !== null)
                        || in_array($record?->status, ['COMPLETE', 'PRESENT', 'PROVISIONAL'], true);
                });
            });

            foreach ($attendedAssignments as $assignment) {
                $type = $this->classifyShift($assignment);
                $shiftCounts[$type] = ($shiftCounts[$type] ?? 0) + 1;
                $shiftCounts['total_shifts']++;

                if ($exchangedAssignmentIds->contains($assignment->id)) {
                    $shiftCounts['exchange']++;
                }
            }

            $empExchangeRequestsCount = $approvedExchanges->filter(function ($ex) use ($employee) {
                return (int) $ex->employee_id === (int) $employee->id || (int) $ex->target_employee_id === (int) $employee->id;
            })->count();

            if ($shiftCounts['exchange'] === 0 && $empExchangeRequestsCount > 0) {
                $shiftCounts['exchange'] = $empExchangeRequestsCount;
            }

            $totalShift1 += $shiftCounts['shift_1'];
            $totalShift2 += $shiftCounts['shift_2'];
            $totalShift3 += $shiftCounts['shift_3'];
            $totalMiddle += $shiftCounts['middle'];
            $totalSplit += $shiftCounts['split'];
            $totalExchange += $shiftCounts['exchange'];

            $attendedDays = count($attendedDates);
            $lateUnpermittedCount = max(0, $lateCount - $latePermittedCount);
            $empActualOvertimeMinutes = (int) $empOvertimes->sum('actual_minutes');
            $empOvertimeMinutes = (int) $empOvertimes->sum('verified_minutes');

            $empLeaveDays = 0;
            $empDepositOffDays = 0;
            foreach ($empLeaves as $leave) {
                $days = max(1, (int) ($leave->days_count ?: 1));
                $empLeaveDays += $days;
                if ($leave->type === 'day_off') {
                    $empDepositOffDays += $days;
                }
            }
            $empCutiDays = $empLeaveDays - $empDepositOffDays;

            $mealAmount = (int) $empAllowances->where('status', 'ELIGIBLE')->sum('meal_amount');
            $transportAmount = (int) $empAllowances->where('status', 'ELIGIBLE')->sum('transport_amount');
            $totalAllowance = $mealAmount + $transportAmount;

            $totalScheduledSessions += $scheduledSessions;
            $totalScheduledDays += $scheduledDays;
            $totalAttendedDays += $attendedDays;
            $totalAttendedSessions += $attendedSessionsCount;
            $totalVerifiedMinutes += $verifiedMinutes;
            $totalActualOvertimeMinutes += $empActualOvertimeMinutes;
            $totalOvertimeMinutes += $empOvertimeMinutes;
            $totalMealAllowance += $mealAmount;
            $totalTransportAllowance += $transportAmount;
            $totalLateCount += $lateCount;
            $totalLateMinutes += $lateMinutes;
            $totalReviewCount += $reviewCount;
            $totalLeaveDays += $empLeaveDays;
            $totalCutiDays += $empCutiDays;
            $totalDepositOffDays += $empDepositOffDays;

            $rows[] = [
                'employee' => [
                    ...$this->reportEmployee($employee),
                    'transport_rate' => $transportRates[$employee->id] ?? 0,
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
                'overtime_actual_minutes' => $empActualOvertimeMinutes,
                'overtime_actual_hours_formatted' => $this->formatHoursMinutes($empActualOvertimeMinutes),
                'overtime_minutes' => $empOvertimeMinutes,
                'overtime_hours_formatted' => $this->formatHoursMinutes($empOvertimeMinutes),
                'leave_days' => $empLeaveDays,
                'cuti_days' => $empCutiDays,
                'deposit_off_days' => $empDepositOffDays,
                'meal_allowance' => $mealAmount,
                'transport_allowance' => $transportAmount,
                'total_allowance' => $totalAllowance,
                'shift_counts' => $shiftCounts,
            ];
        }

        $periodStatus = $this->determinePeriodStatus($filters['from'], $filters['to']);
        $rows = $this->sortRowsByDepartmentAndRank($rows);

        $kpi = [
            'total_employees' => count($rows),
            'total_scheduled_days' => $totalScheduledDays,
            'total_scheduled_sessions' => $totalScheduledSessions,
            'total_attended_days' => $totalAttendedDays,
            'total_attended_sessions' => $totalAttendedSessions,
            'total_verified_minutes' => $totalVerifiedMinutes,
            'total_verified_hours_formatted' => $this->formatHoursMinutes($totalVerifiedMinutes),
            'total_actual_overtime_minutes' => $totalActualOvertimeMinutes,
            'total_actual_overtime_hours_formatted' => $this->formatHoursMinutes($totalActualOvertimeMinutes),
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'total_overtime_hours_formatted' => $this->formatHoursMinutes($totalOvertimeMinutes),
            'total_meal_allowance' => $totalMealAllowance,
            'total_transport_allowance' => $totalTransportAllowance,
            'total_allowance_amount' => $totalMealAllowance + $totalTransportAllowance,
            'total_late_count' => $totalLateCount,
            'total_late_minutes' => $totalLateMinutes,
            'total_review_count' => $totalReviewCount,
            'total_leave_days' => $totalLeaveDays,
            'total_cuti_days' => $totalCutiDays,
            'total_deposit_off_days' => $totalDepositOffDays,
            'total_middle' => $totalMiddle,
            'total_shift_1' => $totalShift1,
            'total_shift_2' => $totalShift2,
            'total_shift_3' => $totalShift3,
            'total_split' => $totalSplit,
            'total_exchange' => $totalExchange,
            'attendance_rate' => $totalScheduledDays > 0
                ? (int) round(($totalAttendedDays / $totalScheduledDays) * 100)
                : 0,
        ];

        return [
            'filters' => $filters,
            'period_status' => $periodStatus,
            'kpi' => $kpi,
            'rows' => $rows,
            'groups' => $this->groupRowsByDepartment($rows),
            'period_label' => $this->formatPeriodLabel($filters['from'], $filters['to']),
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
                'overtimeOrders' => fn (HasMany $query) => $query->where('status', 'APPROVED')->orderBy('id'),
            ])
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->orderByDesc('start_at')
            ->orderByDesc('id');

        $paginator = $sessionQuery->paginate($perPage);

        // Fetch each daily allowance once; the row formatter places it on its anchor shift only.
        $employeeIds = $paginator->getCollection()->map(fn (ShiftSession $session) => $session->assignment->employee_id)->unique()->all();
        $allowances = AllowanceResult::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->get()
            ->keyBy(fn (AllowanceResult $allowance) => $allowance->employee_id.':'.$allowance->work_date);

        $paginator->getCollection()->transform(function (ShiftSession $session) use ($allowances) {
            $key = $session->assignment->employee_id.':'.$session->assignment->work_date;

            return $this->formatSessionDetailRow($session, $allowances->get($key));
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
                'overtimeOrders' => fn (HasMany $query) => $query->where('status', 'APPROVED')->orderBy('id'),
            ])
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->orderByDesc('start_at')
            ->orderByDesc('id');

        $sessions = $sessionQuery->get();
        $employeeIds = $sessions->map(fn (ShiftSession $session) => $session->assignment->employee_id)->unique()->all();
        $allowances = AllowanceResult::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->get()
            ->keyBy(fn (AllowanceResult $allowance) => $allowance->employee_id.':'.$allowance->work_date);

        return $sessions->map(function (ShiftSession $session) use ($allowances) {
            $key = $session->assignment->employee_id.':'.$session->assignment->work_date;

            return $this->formatSessionDetailRow($session, $allowances->get($key));
        });
    }

    public function getOvertime(User $user, array $filters): Collection
    {
        $employeeIds = $this->visibleActiveEmployees($user)->select('employees.id');

        return OvertimeOrder::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->where('status', 'APPROVED')
            ->with(['employee.department', 'session.assignment', 'decisionMaker'])
            ->when(! empty($filters['department_id']), fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $filters['department_id'])))
            ->when(! empty($filters['employee_id']), fn (Builder $q) => $q->where('employee_id', $filters['employee_id']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $term = trim($filters['q']);
                $q->whereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
            })
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OvertimeOrder $order) => [
                ...$order->toApi(),
                'execution_status' => $order->actual_minutes > 0 ? 'REALIZED' : 'NOT_REALIZED',
            ]);
    }

    public function formatSessionDetailRow(ShiftSession $session, ?AllowanceResult $allowance): array
    {
        $assignment = $session->assignment;
        if ($allowance && ((int) $allowance->shift_assignment_id !== (int) $assignment->id || $session->seq !== 1)) {
            $allowance = null;
        }
        $employee = $assignment->employee;
        $record = $session->attendanceRecord;
        $overtimeOrders = $session->overtimeOrders->where('status', 'APPROVED')->values();
        $recordOvertimeOrder = $record?->overtimeOrder;
        if (
            $recordOvertimeOrder?->status === 'APPROVED'
            && $recordOvertimeOrder->shift_session_id === null
            && $recordOvertimeOrder->attendanceRecord()->count() === 1
            && ! $overtimeOrders->contains('id', $recordOvertimeOrder->id)
        ) {
            $overtimeOrders->push($recordOvertimeOrder);
        }
        $overtimeActualMinutes = (int) $overtimeOrders->sum('actual_minutes');
        $overtimeVerifiedMinutes = (int) $overtimeOrders->sum('verified_minutes');
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
            : ($record?->provisional_out_at ? WorkTime::clock($record->provisional_out_at, $assignment->work_date) : '-');
        $autoCheckoutClock = $record?->auto_checkout_at
            ? WorkTime::clock($record->auto_checkout_at, $assignment->work_date)
            : '-';

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
                'checkout_source' => $record?->checkout_source ?? ($record?->provisional_out_at ? 'SYSTEM_AUTO' : null),
                'checkout_source_label' => $record?->checkoutSourceLabel(),
                'auto_checkout_clock' => $autoCheckoutClock,
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
            'overtime_actual_minutes' => $overtimeActualMinutes,
            'overtime_verified_minutes' => $overtimeVerifiedMinutes,
            'overtime_minutes' => $overtimeVerifiedMinutes,
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

    /**
     * Outstanding cuti & deposit off per karyawan untuk satu tahun saldo.
     * Saldo awal = hak tahunan (kuota prorata + penyesuaian), pemakaian = cuti APPROVED
     * dipecah per bulan kalender, saldo akhir = saldo awal - pemakaian.
     */
    public function getLeaveOutstanding(User $user, int $year, array $filters = []): array
    {
        $employees = $this->visibleActiveEmployees($user)
            ->with(['department:id,name', 'position:id,name', 'level:id,code,name'])
            ->when(! empty($filters['department_id']), fn (Builder $q) => $q->where('department_id', $filters['department_id']))
            ->when(! empty($filters['employee_id']), fn (Builder $q) => $q->where('id', $filters['employee_id']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $term = trim($filters['q']);
                $q->where(fn (Builder $sub) => $sub->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->get();

        $transportRates = $this->transportRatesFor($employees, "{$year}-12-31");
        $leaves = LeaveRequest::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('status', 'APPROVED')
            ->whereYear('work_date', $year)
            ->get()
            ->groupBy('employee_id');

        $calculator = app(CalculateLeaveBalance::class);
        $emptyMonths = array_fill_keys(range(1, 12), ['cuti' => 0, 'deposit_off' => 0]);
        $totals = [
            'opening_cuti' => 0, 'opening_deposit_off' => 0,
            'months' => $emptyMonths,
            'closing_cuti' => 0, 'closing_deposit_off' => 0,
        ];
        $rows = [];

        foreach ($employees as $employee) {
            $balance = $calculator->for($employee, $year);
            $months = $emptyMonths;
            foreach ($leaves->get($employee->id, collect()) as $leave) {
                $bucket = $leave->type === 'day_off' ? 'deposit_off' : 'cuti';
                foreach ($this->leaveDaysPerMonth($leave, $year) as $month => $days) {
                    $months[$month][$bucket] += $days;
                }
            }

            $usedCuti = array_sum(array_column($months, 'cuti'));
            $usedDepositOff = array_sum(array_column($months, 'deposit_off'));
            $openingCuti = (int) ($balance['regular']['entitled'] ?? 0);
            $openingDepositOff = (int) ($balance['day_off']['entitled'] ?? 0);
            $row = [
                'employee' => [
                    ...$this->reportEmployee($employee),
                    'transport_rate' => $transportRates[$employee->id] ?? 0,
                ],
                'opening_cuti' => $openingCuti,
                'opening_deposit_off' => $openingDepositOff,
                'months' => $months,
                'used_cuti' => $usedCuti,
                'used_deposit_off' => $usedDepositOff,
                'closing_cuti' => $openingCuti - $usedCuti,
                'closing_deposit_off' => $openingDepositOff - $usedDepositOff,
            ];
            $rows[] = $row;

            $totals['opening_cuti'] += $openingCuti;
            $totals['opening_deposit_off'] += $openingDepositOff;
            $totals['closing_cuti'] += $row['closing_cuti'];
            $totals['closing_deposit_off'] += $row['closing_deposit_off'];
            foreach ($months as $month => $used) {
                $totals['months'][$month]['cuti'] += $used['cuti'];
                $totals['months'][$month]['deposit_off'] += $used['deposit_off'];
            }
        }

        $rows = $this->sortRowsByDepartmentAndRank($rows);

        return [
            'year' => $year,
            'period_label' => self::MONTHS_ID[1]." {$year} s.d. ".self::MONTHS_ID[12]." {$year}",
            'month_names' => self::MONTHS_ID,
            'rows' => $rows,
            'groups' => $this->groupRowsByDepartment($rows),
            'totals' => $totals,
            'total_employees' => count($rows),
            'generated_at_local' => WorkTime::local(WorkTime::now())?->format('d/m/Y H:i'),
        ];
    }

    /** @return array<int, int> bulan => jumlah hari cuti di tahun tsb. */
    private function leaveDaysPerMonth(LeaveRequest $leave, int $year): array
    {
        $start = CarbonImmutable::parse($leave->work_date)->startOfDay();
        $end = CarbonImmutable::parse($leave->effectiveEndDate())->startOfDay();
        $total = max(1, (int) ($leave->days_count ?: ($start->diffInDays($end) + 1)));
        $perMonth = [];
        $counted = 0;
        for ($day = $start; $day->lte($end) && $counted < $total; $day = $day->addDay()) {
            // Sisa hari yang melewati akhir tahun tetap dihitung di Desember agar total = saldo terpakai.
            $month = $day->year === $year ? $day->month : 12;
            $perMonth[$month] = ($perMonth[$month] ?? 0) + 1;
            $counted++;
        }
        if ($counted < $total) {
            $month = $start->month;
            $perMonth[$month] = ($perMonth[$month] ?? 0) + ($total - $counted);
        }

        return $perMonth;
    }

    public function formatPeriodLabel(string $from, string $to): string
    {
        return $this->formatDateId($from).' s.d. '.$this->formatDateId($to);
    }

    public function formatDateId(?string $date): string
    {
        if (! $date) {
            return '-';
        }
        $carbon = CarbonImmutable::parse($date);

        return $carbon->day.' '.self::MONTHS_ID[$carbon->month].' '.$carbon->year;
    }

    private function reportEmployee(Employee $employee): array
    {
        $startedOn = $employee->started_on ? substr((string) $employee->started_on, 0, 10) : null;

        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'name' => $employee->name,
            'department_id' => $employee->department_id,
            'department' => $employee->department?->name ?? '-',
            'position' => $employee->position?->name ?? '-',
            'level' => $employee->level?->name ?? '-',
            'started_on' => $startedOn,
            'started_on_formatted' => $startedOn ? CarbonImmutable::parse($startedOn)->format('d/m/Y') : '-',
            'avatar_url' => $employee->avatar_url,
        ];
    }

    /**
     * Tarif tunjangan transport per hari yang berlaku pada $date (override karyawan > kebijakan level),
     * sama dengan aturan CalculateCycleAllowance. Dipakai sebagai urutan "level" di laporan.
     *
     * @return array<int, int>
     */
    private function transportRatesFor(Collection $employees, string $date): array
    {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $active = fn ($query) => $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));

        $levelAssignments = EmployeeLevelAssignment::query()->whereIn('employee_id', $ids)->where($active)
            ->orderByDesc('effective_from')->get()->unique('employee_id')->keyBy('employee_id');
        $overrides = EmployeeAllowanceOverride::query()->whereIn('employee_id', $ids)->where($active)
            ->orderByDesc('effective_from')->get()->unique('employee_id')->keyBy('employee_id');

        $levelIds = $employees->map(fn (Employee $e) => $levelAssignments->get($e->id)?->level_id ?? $e->level_id)->filter()->unique()->all();
        $policies = AllowancePolicy::query()->whereIn('level_id', $levelIds)->where($active)
            ->orderByDesc('effective_from')->get()->unique('level_id')->keyBy('level_id');

        $rates = [];
        foreach ($employees as $employee) {
            $levelId = $levelAssignments->get($employee->id)?->level_id ?? $employee->level_id;
            $transport = $overrides->get($employee->id)?->transport_amount
                ?? ($levelId ? $policies->get($levelId)?->transport_amount : null);
            $rates[$employee->id] = (int) ($transport ?? 0);
        }

        return $rates;
    }

    /**
     * Urutkan per departemen (abjad, "Tanpa Departemen" terakhir), lalu level tertinggi:
     * tarif tunjangan transport terbesar, tanggal mulai bekerja paling awal, lalu nama.
     */
    public function sortRowsByDepartmentAndRank(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            $ea = $a['employee'];
            $eb = $b['employee'];
            $deptA = $ea['department_id'] ? 0 : 1;
            $deptB = $eb['department_id'] ? 0 : 1;

            return [$deptA, mb_strtolower((string) $ea['department']), -($ea['transport_rate'] ?? 0), $ea['started_on'] ?? '9999-12-31', mb_strtolower($ea['name']), $ea['id']]
                <=> [$deptB, mb_strtolower((string) $eb['department']), -($eb['transport_rate'] ?? 0), $eb['started_on'] ?? '9999-12-31', mb_strtolower($eb['name']), $eb['id']];
        });

        return $rows;
    }

    /** @return array<int, array{department_id: ?int, department: string, rows: array}> */
    public function groupRowsByDepartment(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['employee']['department_id'] ?? 0;
            $groups[$key] ??= [
                'department_id' => $row['employee']['department_id'] ?? null,
                'department' => $row['employee']['department_id'] ? $row['employee']['department'] : self::NO_DEPARTMENT_LABEL,
                'rows' => [],
            ];
            $groups[$key]['rows'][] = $row;
        }

        return array_values($groups);
    }

    private function emptyKpi(): array
    {
        return [
            'total_employees' => 0,
            'total_scheduled_days' => 0,
            'total_scheduled_sessions' => 0,
            'total_attended_days' => 0,
            'total_attended_sessions' => 0,
            'total_verified_minutes' => 0,
            'total_verified_hours_formatted' => '0j 0m',
            'total_actual_overtime_minutes' => 0,
            'total_actual_overtime_hours_formatted' => '0j 0m',
            'total_overtime_minutes' => 0,
            'total_overtime_hours_formatted' => '0j 0m',
            'total_meal_allowance' => 0,
            'total_transport_allowance' => 0,
            'total_allowance_amount' => 0,
            'total_late_count' => 0,
            'total_late_minutes' => 0,
            'total_review_count' => 0,
            'total_leave_days' => 0,
            'total_cuti_days' => 0,
            'total_deposit_off_days' => 0,
            'total_middle' => 0,
            'total_shift_1' => 0,
            'total_shift_2' => 0,
            'total_shift_3' => 0,
            'total_split' => 0,
            'total_exchange' => 0,
            'attendance_rate' => 0,
        ];
    }

    public function classifyShift(ShiftAssignment $assignment): string
    {
        $sessions = $assignment->sessions;
        $categoryName = strtolower($assignment->category?->name ?? '');
        $templateName = strtolower($assignment->template_name ?? '');

        // 1. Split Khusus
        if (
            $assignment->template_type === 'special'
            || $sessions->count() > 1
            || str_contains($categoryName, 'split')
            || str_contains($templateName, 'split')
            || str_contains($categoryName, 'khusus')
            || str_contains($templateName, 'khusus')
        ) {
            return 'split';
        }

        // 2. Middle shift has its own column in the rekap template.
        if (str_contains($categoryName, 'middle') || str_contains($templateName, 'middle')) {
            return 'middle';
        }

        // 3. Keyword matching for Shift 1, 2, 3
        if (
            str_contains($categoryName, 'shift 1')
            || str_contains($templateName, 'shift 1')
            || str_contains($categoryName, 'shift 01')
            || str_contains($templateName, 'shift 01')
            || str_contains($categoryName, 'pagi')
            || str_contains($templateName, 'pagi')
            || str_contains($categoryName, 'morning')
            || str_contains($templateName, 'morning')
        ) {
            return 'shift_1';
        }

        if (
            str_contains($categoryName, 'shift 2')
            || str_contains($templateName, 'shift 2')
            || str_contains($categoryName, 'shift 02')
            || str_contains($templateName, 'shift 02')
            || str_contains($categoryName, 'siang')
            || str_contains($templateName, 'siang')
            || str_contains($categoryName, 'sore')
            || str_contains($templateName, 'sore')
            || str_contains($categoryName, 'afternoon')
            || str_contains($templateName, 'afternoon')
            || str_contains($categoryName, 'evening')
            || str_contains($templateName, 'evening')
        ) {
            return 'shift_2';
        }

        if (
            str_contains($categoryName, 'shift 3')
            || str_contains($templateName, 'shift 3')
            || str_contains($categoryName, 'shift 03')
            || str_contains($templateName, 'shift 03')
            || str_contains($categoryName, 'malam')
            || str_contains($templateName, 'malam')
            || str_contains($categoryName, 'night')
            || str_contains($templateName, 'night')
        ) {
            return 'shift_3';
        }

        // 4. Fallback based on start hour of first session (local time)
        $firstSession = $sessions->first();
        if ($firstSession && $firstSession->start_at) {
            $localTime = WorkTime::local($firstSession->start_at);
            $hour = (int) $localTime?->format('H');
            if ($hour >= 5 && $hour < 12) {
                return 'shift_1';
            }
            if ($hour >= 12 && $hour < 19) {
                return 'shift_2';
            }

            return 'shift_3';
        }

        return 'shift_1';
    }
}
