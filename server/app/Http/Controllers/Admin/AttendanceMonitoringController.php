<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\ShiftAssignment;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceMonitoringController extends Controller
{
    public function filters(Request $request): JsonResponse
    {
        $employees = EmployeeController::visibleTo($request->user())
            ->orderBy('name')->get(['id', 'name', 'code', 'department_id']);
        $departments = Department::query()->whereIn('id', $employees->pluck('department_id')->filter()->unique())
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['employees' => $employees, 'departments' => $departments]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $sessions = $this->sessions($request->user(), $filters);
        $sessionIds = (clone $sessions)->select('shift_sessions.id');
        $records = AttendanceRecord::query()->whereIn('shift_session_id', clone $sessionIds);
        $review = (clone $sessions)->where(fn (Builder $query) => $query
            ->whereHas('attendanceRecord', fn (Builder $record) => $record->where('needs_review', true))
            ->orWhere(fn (Builder $query) => $query
                ->whereDoesntHave('attendanceRecord')
                ->whereHas('attendanceEvents', fn (Builder $event) => $event->where('result', 'REVIEW_REQUIRED'))));

        $scheduledSessions = (clone $sessions)->count();
        $checkedInSessions = (clone $records)->whereNotNull('check_in_at')->count();
        $reviewSessionsCount = (clone $review)->count();

        $recent = (clone $sessions)
            ->where(fn (Builder $query) => $query->whereHas('attendanceRecord')->orWhereHas('attendanceEvents'))
            ->with(['assignment.employee.department:id,name', 'attendanceRecord.latePermission', 'attendanceEvents'])
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->limit(8)->get();

        $reviewItems = (clone $review)
            ->with(['assignment.employee.department:id,name', 'attendanceRecord.latePermission', 'attendanceEvents'])
            ->limit(5)->get();

        $pendingLeavesCount = LeaveRequest::query()->where('status', 'PENDING')->count();

        return response()->json([
            'filters' => $filters,
            'summary' => [
                'scheduled_employees' => $this->assignments($request->user(), $filters)->distinct()->count('employee_id'),
                'scheduled_sessions' => $scheduledSessions,
                'checked_in_sessions' => $checkedInSessions,
                'review_sessions' => $reviewSessionsCount,
                'verified_minutes' => (clone $records)->sum('verified_minutes'),
                'attendance_rate' => $scheduledSessions > 0 ? (int) round(($checkedInSessions / $scheduledSessions) * 100) : 0,
                'on_time_count' => (clone $records)->whereNotNull('check_in_at')->where('is_late', false)->count(),
                'late_count' => (clone $records)->whereNotNull('check_in_at')->where('is_late', true)->count(),
                'in_progress_count' => (clone $records)->whereNotNull('check_in_at')->whereNull('check_out_at')->count(),
                'completed_count' => (clone $records)->whereNotNull('check_out_at')->count(),
                'pending_leaves_count' => $pendingLeavesCount,
            ],
            'recent' => $recent->map(fn (ShiftSession $session) => $this->row($session))->values(),
            'review_items' => $reviewItems->map(fn (ShiftSession $session) => $this->row($session))->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $perPage = min(max($request->integer('per_page', 50), 1), 100);
        $paginator = $this->sessions($request->user(), $filters)
            ->with(['assignment.employee.department:id,name', 'attendanceRecord.latePermission', 'attendanceEvents'])
            ->orderBy('start_at')->paginate($perPage);

        return response()->json([
            'filters' => $filters,
            'data' => $paginator->getCollection()->map(fn (ShiftSession $session) => $this->row($session))->values(),
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

    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $data['from'] ??= WorkTime::today();
        $data['to'] ??= $data['from'];

        if ($data['to'] < $data['from']) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Tanggal akhir tidak boleh sebelum tanggal awal.', 422);
        }
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 62) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Rentang paling panjang 62 hari.', 422);
        }

        return $data;
    }

    private function assignments(User $user, array $filters): Builder
    {
        $employeeIds = EmployeeController::visibleTo($user)->select('employees.id');

        return ShiftAssignment::query()
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->whereIn('status', ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'])
            ->whereIn('employee_id', $employeeIds)
            ->when($filters['department_id'] ?? null, fn (Builder $query, $id) => $query->whereHas('employee', fn (Builder $employee) => $employee->where('department_id', $id)))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $id) => $query->where('employee_id', $id))
            ->when($filters['q'] ?? null, fn (Builder $query, $term) => $query->whereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")));
    }

    private function sessions(User $user, array $filters): Builder
    {
        return ShiftSession::query()->whereIn('shift_assignment_id', $this->assignments($user, $filters)->select('id'));
    }

    private function row(ShiftSession $session): array
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

        return [
            'id' => $session->id,
            'work_date' => $assignment->work_date,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'code' => $employee->code,
                'department' => $employee->department?->name,
                'avatar_url' => $employee->avatar_url,
            ],
            'shift' => ['name' => $assignment->template_name, 'seq' => $session->seq, 'start_at' => WorkTime::iso($session->start_at), 'end_at' => WorkTime::iso($session->end_at)],
            'attendance' => [
                'state' => $state,
                'check_in_at' => WorkTime::iso($record?->check_in_at),
                'check_out_at' => WorkTime::iso($record?->check_out_at),
                'provisional_out_at' => WorkTime::iso($record?->provisional_out_at),
                'is_late' => (bool) $record?->is_late,
                'late_permission_status' => $record?->latePermission?->status,
                'needs_review' => $review,
                'review_reason' => $record
                    ? ($record->needs_review ? $record->review_reason : null)
                    : $events->first(fn ($event) => $event->result === 'REVIEW_REQUIRED')?->reason_code,
                'verified_minutes' => $record?->verified_minutes ?? 0,
                'last_event_at' => WorkTime::iso($events->last()?->effective_at),
                'last_event_source' => $events->last()?->source,
            ],
        ];
    }
}
