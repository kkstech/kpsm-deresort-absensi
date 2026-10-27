<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ManageAttendanceWorkflow;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\LatePermission;
use App\Support\WorkTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceWorkflowController extends Controller
{
    public function permissions(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }
        if ($employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }
        $items = LatePermission::query()->with('session.assignment', 'attachments')->where('employee_id', $employee->id)
            ->latest()->limit(100)->get()->map(fn (LatePermission $item) => $this->permissionData($item));

        return response()->json(['data' => $items]);
    }

    public function storePermission(Request $request, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'shift_session_id' => ['required', 'integer', 'exists:shift_sessions,id'],
            'allowed_until' => ['nullable', 'date', 'prohibits:allowed_minutes'],
            'allowed_minutes' => ['nullable', 'integer', 'between:0,1440', 'prohibits:allowed_until'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        $permission = $workflow->submitLatePermission($request->user(), $this->employee($request), $data['shift_session_id'], $data, $request->file('evidence'));

        return response()->json(['data' => $this->permissionData($permission)], 201);
    }

    public function cancelPermission(Request $request, int $permission, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        return response()->json(['data' => $this->permissionData($workflow->cancelLatePermission($request->user(), $permission)->load('session.assignment', 'attachments'))]);
    }

    public function corrections(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }
        if ($employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }
        $items = AttendanceCorrection::query()->with('session.assignment', 'attachments')->where('employee_id', $employee->id)
            ->latest()->limit(100)->get()->map(fn (AttendanceCorrection $item) => $this->correctionData($item));

        return response()->json(['data' => $items]);
    }

    public function storeCorrection(Request $request, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'shift_session_id' => ['required', 'integer', 'exists:shift_sessions,id'],
            'requested_check_in_at' => ['nullable', 'date'],
            'requested_check_out_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        if (! isset($data['requested_check_in_at']) && ! isset($data['requested_check_out_at'])) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Isi minimal satu waktu yang akan dikoreksi.', 422);
        }
        $correction = $workflow->submitCorrection($request->user(), $this->employee($request), $data['shift_session_id'], $data, $request->file('evidence'));

        return response()->json(['data' => $this->correctionData($correction)], 201);
    }

    public function cancelCorrection(Request $request, int $correction, ManageAttendanceWorkflow $workflow): JsonResponse
    {
        return response()->json(['data' => $this->correctionData($workflow->cancelCorrection($request->user(), $correction)->load('session.assignment', 'attachments'))]);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        if (! $employee || $employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }

        return $employee;
    }

    private function permissionData(LatePermission $item): array
    {
        return [
            'id' => $item->id,
            'shift_session_id' => $item->shift_session_id,
            'work_date' => $item->session->assignment->work_date,
            'shift' => $item->session->assignment->template_name.' · sesi '.$item->session->seq,
            'allowed_until' => WorkTime::iso($item->allowed_until),
            'allowed_minutes' => $item->allowed_minutes,
            'reason' => $item->reason,
            'status' => $item->status,
            'decision_reason' => $item->decision_reason,
            'attachments' => $item->attachments->map(fn ($attachment) => $this->attachmentData($attachment))->values(),
        ];
    }

    private function correctionData(AttendanceCorrection $item): array
    {
        return [
            'id' => $item->id,
            'shift_session_id' => $item->shift_session_id,
            'work_date' => $item->session->assignment->work_date,
            'shift' => $item->session->assignment->template_name.' · sesi '.$item->session->seq,
            'requested_check_in_at' => WorkTime::iso($item->requested_check_in_at),
            'requested_check_out_at' => WorkTime::iso($item->requested_check_out_at),
            'reason' => $item->reason,
            'status' => $item->status,
            'decision_reason' => $item->decision_reason,
            'attachments' => $item->attachments->map(fn ($attachment) => $this->attachmentData($attachment))->values(),
        ];
    }

    private function attachmentData($attachment): array
    {
        return ['id' => $attachment->id, 'name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes];
    }
}
