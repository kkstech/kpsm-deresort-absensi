<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ManageAttendanceWorkflow;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\LatePermission;
use App\Support\WorkflowNotifier;
use App\Support\WorkTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceWorkflowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Hanya Admin atau Super Admin yang dapat melihat antrean persetujuan.');
        $filters = $request->validate(['status' => ['nullable', 'in:PENDING,APPROVED,REJECTED,CANCELLED']]);
        $employeeIds = EmployeeController::visibleTo($request->user())->select('employees.id');
        $relations = ['employee.department:id,name', 'session.assignment', 'attachments'];
        $status = $filters['status'] ?? 'PENDING';
        $adminTierOnly = $status === 'PENDING' && ! $request->user()->isSuperAdmin();

        return response()->json([
            'late_permissions' => LatePermission::query()->with($relations)->where('status', $status)
                ->whereIn('employee_id', $employeeIds)->when($adminTierOnly, fn ($query) => $query->where('approval_tier', 'admin'))->latest()->limit(100)->get()->map(fn (LatePermission $item) => $this->latePermissionData($item)),
            'corrections' => AttendanceCorrection::query()->with($relations)->where('status', $status)
                ->whereIn('employee_id', $employeeIds)->when($adminTierOnly, fn ($query) => $query->where('approval_tier', 'admin'))->latest()->limit(100)->get()->map(fn (AttendanceCorrection $item) => $this->correctionData($item)),
        ]);
    }

    public function directLatePermission(Request $request, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'shift_session_id' => ['required', 'integer', 'exists:shift_sessions,id'],
            'allowed_until' => ['nullable', 'date', 'prohibits:allowed_minutes'],
            'allowed_minutes' => ['nullable', 'integer', 'between:0,1440', 'prohibits:allowed_until'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ], [
            'allowed_until.prohibits' => 'Isi jam target hadir atau durasi izin, jangan keduanya.',
            'allowed_minutes.prohibits' => 'Isi jam target hadir atau durasi izin, jangan keduanya.',
            'allowed_minutes.between' => 'Durasi izin harus 0 sampai 1440 menit.',
        ]);
        $permission = $workflow->createDirectLatePermission($request->user(), $data['shift_session_id'], $data, $request->file('evidence'));
        app(WorkflowNotifier::class)->permissionDecision($permission);

        return response()->json(['data' => $this->latePermissionData($permission->load('employee.department', 'session.assignment', 'attachments'))], 201);
    }

    public function decideLatePermission(Request $request, int $permission, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'decision_reason' => ['required', 'string', 'max:1000'],
        ]);
        $wasPending = LatePermission::query()->whereKey($permission)->where('status', 'PENDING')->exists();
        $result = $workflow->decideLatePermission($request->user(), $permission, $data['decision'], $data['decision_reason']);
        if ($wasPending) {
            app(WorkflowNotifier::class)->permissionDecision($result);
        }

        return response()->json(['data' => $this->latePermissionData($result->load('employee.department', 'session.assignment', 'attachments'))]);
    }

    public function directCorrection(Request $request, int $session, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        if (! isset($data['check_in_at']) && ! isset($data['check_out_at'])) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Isi minimal satu waktu yang akan dikoreksi.', 422);
        }
        $correction = $workflow->directCorrection($request->user(), $session, $data, $request->file('evidence'));

        return response()->json(['data' => $this->correctionData($correction->load('employee.department', 'session.assignment', 'attachments'))], 201);
    }

    public function decideCorrection(Request $request, int $correction, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'decision_reason' => ['required', 'string', 'max:1000'],
        ]);
        $result = $workflow->decideCorrection($request->user(), $correction, $data['decision'], $data['decision_reason']);

        return response()->json(['data' => $this->correctionData($result->load('employee.department', 'session.assignment', 'attachments'))]);
    }

    public function verify(Request $request, int $session, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'check_out_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $record = $workflow->verifyCheckout($request->user(), $session, $data['check_out_at'], $data['reason']);

        return response()->json(['data' => $record->toApi()]);
    }

    private function latePermissionData(LatePermission $item): array
    {
        return [
            'id' => $item->id,
            'shift_session_id' => $item->shift_session_id,
            'permission_type' => $item->permission_type,
            'employee' => ['id' => $item->employee->id, 'name' => $item->employee->name, 'code' => $item->employee->code, 'avatar_url' => $item->employee->avatar_url, 'department' => $item->employee->department?->name],
            'work_date' => $item->session->assignment->work_date,
            'shift' => $item->session->assignment->template_name.' · sesi '.$item->session->seq,
            'allowed_until' => WorkTime::iso($item->allowed_until),
            'allowed_minutes' => $item->allowed_minutes,
            'leave_at' => WorkTime::iso($item->leave_at),
            'return_at' => WorkTime::iso($item->return_at),
            'reason' => $item->reason,
            'status' => $item->status,
            'approval_tier' => $item->approval_tier,
            'requester_id' => $item->requester_id,
            'decision_reason' => $item->decision_reason,
            'attachments' => $item->attachments->map(fn ($attachment) => $this->attachmentData($attachment))->values(),
        ];
    }

    private function correctionData(AttendanceCorrection $item): array
    {
        return [
            'id' => $item->id,
            'shift_session_id' => $item->shift_session_id,
            'employee' => ['id' => $item->employee->id, 'name' => $item->employee->name, 'code' => $item->employee->code, 'avatar_url' => $item->employee->avatar_url, 'department' => $item->employee->department?->name],
            'work_date' => $item->session->assignment->work_date,
            'shift' => $item->session->assignment->template_name.' · sesi '.$item->session->seq,
            'requested_check_in_at' => WorkTime::iso($item->requested_check_in_at),
            'requested_check_out_at' => WorkTime::iso($item->requested_check_out_at),
            'reason' => $item->reason,
            'status' => $item->status,
            'approval_tier' => $item->approval_tier,
            'before' => $item->before_json,
            'after' => $item->after_json,
            'decision_reason' => $item->decision_reason,
            'attachments' => $item->attachments->map(fn ($attachment) => $this->attachmentData($attachment))->values(),
        ];
    }

    private function attachmentData($attachment): array
    {
        return ['id' => $attachment->id, 'name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes];
    }
}
