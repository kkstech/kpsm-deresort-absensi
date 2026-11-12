<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\LatePermission;
use App\Models\LeaveRequest;
use App\Models\OvertimeOrder;
use App\Models\ShiftAssignment;
use App\Models\ShiftExchangeRequest;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceMonitoringController extends Controller
{
    public function events(Request $request, int $session): JsonResponse
    {
        $employeeIds = EmployeeController::visibleTo($request->user())->select('employees.id');
        $item = ShiftSession::query()->whereKey($session)
            ->whereHas('assignment', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))
            ->firstOrFail();

        return response()->json(['data' => $item->attendanceEvents()->orderBy('server_received_at')->get()->map(fn ($event) => [
            'event_uuid' => $event->event_uuid,
            'type' => $event->type,
            'source' => $event->source,
            'result' => $event->result,
            'reason_code' => $event->reason_code,
            'effective_at' => WorkTime::iso($event->effective_at),
            'device_time' => WorkTime::iso($event->device_time),
            'device_timezone' => $event->device_timezone,
            'server_received_at' => WorkTime::iso($event->server_received_at),
            'latitude' => $event->latitude !== null ? (float) $event->latitude : null,
            'longitude' => $event->longitude !== null ? (float) $event->longitude : null,
            'accuracy_meters' => $event->accuracy_meters,
            'distance_meters' => $event->distance_meters,
            'is_mocked' => (bool) $event->is_mocked,
            'photo_url' => $event->photo_url,
        ])->values()]);
    }

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
        $recentPage = $request->validate(['recent_page' => ['sometimes', 'integer', 'min:1']])['recent_page'] ?? 1;
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
            ->with($this->eagerRelations())
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->paginate(8, ['*'], 'recent_page', $recentPage);

        $reviewItems = (clone $review)
            ->with($this->eagerRelations())
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->limit(5)->get();

        $pendingLeavesCount = LeaveRequest::query()->where('status', 'PENDING')->count();
        $employeeIds = EmployeeController::visibleTo($request->user())->select('employees.id');
        $pendingLateCount = LatePermission::query()->where('status', 'PENDING')->whereIn('employee_id', $employeeIds)->count();
        $pendingCorrectionsCount = AttendanceCorrection::query()->where('status', 'PENDING')->whereIn('employee_id', $employeeIds)->count();
        $pendingOvertimeCount = OvertimeOrder::query()->where('status', 'PENDING')->whereIn('employee_id', $employeeIds)->count();
        $pendingExchangesCount = ShiftExchangeRequest::query()->where('status', 'PENDING')->whereIn('employee_id', $employeeIds)->count();
        $pendingApprovalsCount = $pendingLateCount + $pendingCorrectionsCount + $pendingOvertimeCount + $pendingExchangesCount;

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
                'pending_permissions_count' => $pendingLateCount,
                'pending_approvals_count' => $pendingApprovalsCount,
            ],
            'recent' => $recent->getCollection()->map(fn (ShiftSession $session) => $this->row($session))->values(),
            'recent_meta' => [
                'current_page' => $recent->currentPage(),
                'last_page' => $recent->lastPage(),
                'per_page' => $recent->perPage(),
                'total' => $recent->total(),
                'from' => $recent->firstItem(),
                'to' => $recent->lastItem(),
            ],
            'review_items' => $reviewItems->map(fn (ShiftSession $session) => $this->row($session))->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $state = $request->validate(['state' => ['sometimes', 'nullable', 'in:complete,in_progress,late,review']])['state'] ?? null;
        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $sessions = $this->sessions($request->user(), $filters);
        $sessionIds = (clone $sessions)->select('shift_sessions.id');
        $records = AttendanceRecord::query()->whereIn('shift_session_id', clone $sessionIds);
        $filteredSessions = $this->filterSessionsByState(clone $sessions, $state);
        $totalSessions = (clone $sessions)->count();
        $reviewCount = (clone $sessions)->where(fn (Builder $query) => $query
            ->whereHas('attendanceRecord', fn (Builder $record) => $record->where('needs_review', true))
            ->orWhere(fn (Builder $query) => $query
                ->whereDoesntHave('attendanceRecord')
                ->whereHas('attendanceEvents', fn (Builder $event) => $event->where('result', 'REVIEW_REQUIRED'))))->count();

        $paginator = $filteredSessions
            ->with($this->eagerRelations())
            ->withMax('attendanceEvents', 'effective_at')
            ->orderByDesc('attendance_events_max_effective_at')
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'filters' => $filters,
            'data' => $paginator->getCollection()->map(fn (ShiftSession $session) => $this->row($session))->values(),
            'summary' => [
                'total' => $totalSessions,
                'complete' => (clone $records)->where('needs_review', false)->where('is_late', false)->whereNotNull('check_out_at')->count(),
                'in_progress' => (clone $records)->where('needs_review', false)->where('is_late', false)->whereNotNull('check_in_at')->whereNull('check_out_at')->count(),
                'late' => (clone $records)->where('needs_review', false)->where('is_late', true)->count(),
                'review' => $reviewCount,
            ],
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

    private function filterSessionsByState(Builder $sessions, ?string $state): Builder
    {
        return match ($state) {
            'complete' => $sessions->whereHas('attendanceRecord', fn (Builder $record) => $record
                ->where('needs_review', false)->where('is_late', false)->whereNotNull('check_out_at')),
            'in_progress' => $sessions->whereHas('attendanceRecord', fn (Builder $record) => $record
                ->where('needs_review', false)->where('is_late', false)->whereNotNull('check_in_at')->whereNull('check_out_at')),
            'late' => $sessions->whereHas('attendanceRecord', fn (Builder $record) => $record
                ->where('needs_review', false)->where('is_late', true)),
            'review' => $sessions->where(fn (Builder $query) => $query
                ->whereHas('attendanceRecord', fn (Builder $record) => $record->where('needs_review', true))
                ->orWhere(fn (Builder $query) => $query
                    ->whereDoesntHave('attendanceRecord')
                    ->whereHas('attendanceEvents', fn (Builder $event) => $event->where('result', 'REVIEW_REQUIRED')))),
            default => $sessions,
        };
    }

    private function eagerRelations(): array
    {
        return [
            'assignment:id,employee_id,work_date,template_name,status',
            'assignment.employee:id,name,code,department_id,avatar_path',
            'assignment.employee.department:id,name',
            'attendanceRecord:id,shift_session_id,check_in_event_id,check_out_event_id,late_permission_id,overtime_order_id,check_in_at,check_out_at,provisional_out_at,check_in_photo_path,check_out_photo_path,is_late,needs_review,review_reason,verified_minutes,verified_overtime_minutes',
            'attendanceRecord.latePermission:id,status',
            'attendanceEvents:id,shift_session_id,type,source,effective_at,server_received_at,device_time,device_timezone,latitude,longitude,accuracy_meters,distance_meters,is_mocked,result,reason_code,photo_path',
            'latePermissions:id,shift_session_id,permission_type,allowed_until,allowed_minutes,leave_at,return_at,reason,status',
        ];
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
        return ShiftSession::query()->whereNull('retired_at')->whereIn('shift_assignment_id', $this->assignments($user, $filters)->select('id'));
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

        $checkInEvent = ($record?->check_in_event_id ? $events->firstWhere('id', $record->check_in_event_id) : null)
            ?? $events->where('type', 'CHECK_IN')->last();
        $checkOutEvent = ($record?->check_out_event_id ? $events->firstWhere('id', $record->check_out_event_id) : null)
            ?? $events->where('type', 'CHECK_OUT')->last();

        $permPayload = $session->approvedPermissionsPayload();

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
            'shift' => [
                'name' => $assignment->template_name,
                'seq' => $session->seq,
                'start_at' => WorkTime::iso($session->start_at),
                'end_at' => WorkTime::iso($session->end_at),
                'effective_start' => $permPayload['effective_start'],
                'effective_end' => $permPayload['effective_end'],
                'approved_permissions' => $permPayload['approved_permissions'],
            ],
            'attendance' => [
                'state' => $state,
                'check_in_at' => WorkTime::iso($record?->check_in_at),
                'check_in_device_time' => WorkTime::iso($checkInEvent?->device_time),
                'check_in_device_timezone' => $checkInEvent?->device_timezone,
                'check_in_server_received_at' => WorkTime::iso($checkInEvent?->server_received_at),
                'check_in_source' => $checkInEvent?->source,
                'check_out_at' => WorkTime::iso($record?->check_out_at),
                'check_out_device_time' => WorkTime::iso($checkOutEvent?->device_time),
                'check_out_device_timezone' => $checkOutEvent?->device_timezone,
                'check_out_server_received_at' => WorkTime::iso($checkOutEvent?->server_received_at),
                'check_out_source' => $checkOutEvent?->source,
                'provisional_out_at' => WorkTime::iso($record?->provisional_out_at),
                'check_in_photo_url' => $record?->check_in_photo_url,
                'check_out_photo_url' => $record?->check_out_photo_url,
                'is_late' => (bool) $record?->is_late,
                'late_permission_status' => $record?->latePermission?->status,
                'needs_review' => $review,
                'review_reason' => $record
                    ? ($record->needs_review ? $record->review_reason : null)
                    : $events->first(fn ($event) => $event->result === 'REVIEW_REQUIRED')?->reason_code,
                'verified_minutes' => $record?->verified_minutes ?? 0,
                'verified_overtime_minutes' => $record?->verified_overtime_minutes ?? 0,
                'last_event_at' => WorkTime::iso($events->last()?->effective_at),
                'last_event_source' => $events->last()?->source,
                'has_mock_event' => $events->contains('is_mocked', true),
                'location_required' => (bool) ($session->policy_snapshot['use_location_check'] ?? false),
                'check_in_latitude' => $checkInEvent?->latitude !== null ? (float) $checkInEvent->latitude : null,
                'check_in_longitude' => $checkInEvent?->longitude !== null ? (float) $checkInEvent->longitude : null,
                'check_in_accuracy' => $checkInEvent?->accuracy_meters,
                'check_in_distance' => $checkInEvent?->distance_meters,
                'check_out_latitude' => $checkOutEvent?->latitude !== null ? (float) $checkOutEvent->latitude : null,
                'check_out_longitude' => $checkOutEvent?->longitude !== null ? (float) $checkOutEvent->longitude : null,
                'check_out_accuracy' => $checkOutEvent?->accuracy_meters,
                'check_out_distance' => $checkOutEvent?->distance_meters,
            ],
        ];
    }
}
