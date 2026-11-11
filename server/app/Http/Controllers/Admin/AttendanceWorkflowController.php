<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ManageAttendanceWorkflow;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\LatePermission;
use App\Models\OvertimeOrder;
use App\Models\ShiftExchangeRequest;
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

    public function history(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Hanya Admin atau Super Admin yang dapat melihat riwayat persetujuan.');

        $data = $request->validate([
            'type' => ['nullable', 'string', 'in:all,late,correction,overtime,shift_exchange'],
            'status' => ['nullable', 'string', 'in:all,APPROVED,REJECTED,CANCELLED'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $type = $data['type'] ?? 'all';
        $status = $data['status'] ?? 'all';
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        $q = isset($data['q']) ? trim($data['q']) : null;
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 20);

        $employeeIds = EmployeeController::visibleTo($request->user())->pluck('employees.id');
        $items = collect();

        // 1. Izin Terlambat
        if ($type === 'all' || $type === 'late') {
            $query = LatePermission::query()
                ->with(['employee.department:id,name', 'session.assignment', 'decidedBy:id,name', 'attachments'])
                ->whereIn('employee_id', $employeeIds);

            if ($status !== 'all') {
                $query->where('status', $status);
            } else {
                $query->whereIn('status', ['APPROVED', 'REJECTED', 'CANCELLED']);
            }

            if ($from && $to) {
                $query->whereHas('session.assignment', fn ($sq) => $sq->whereBetween('work_date', [$from, $to]));
            }

            if ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
                        ->orWhere('reason', 'like', "%{$q}%")
                        ->orWhere('decision_reason', 'like', "%{$q}%");
                });
            }

            $lateItems = $query->get()->map(function (LatePermission $item) {
                $decidedAt = $item->decided_at ?? $item->updated_at;

                return [
                    'id' => $item->id,
                    'type' => 'late',
                    'type_label' => 'Izin Terlambat',
                    'employee' => [
                        'id' => $item->employee->id,
                        'name' => $item->employee->name,
                        'code' => $item->employee->code,
                        'avatar_url' => $item->employee->avatar_url,
                        'department' => $item->employee->department?->name ?? '-',
                    ],
                    'work_date' => $item->session?->assignment?->work_date,
                    'shift' => $item->session ? ($item->session->assignment?->template_name.' · sesi '.$item->session->seq) : '-',
                    'detail' => $item->allowed_minutes ? "Izin {$item->allowed_minutes} menit" : ($item->allowed_until ? 'Hadir s.d. '.WorkTime::local($item->allowed_until)?->format('H:i') : 'Izin terlambat'),
                    'reason' => $item->reason,
                    'status' => $item->status,
                    'decided_by' => $item->decidedBy?->name ?? 'Sistem / Admin',
                    'decided_at' => WorkTime::iso($decidedAt),
                    'decided_at_formatted' => WorkTime::local($decidedAt)?->format('d/m/Y H:i'),
                    'decision_reason' => $item->decision_reason ?? '-',
                    'attachments' => $item->attachments->map(fn ($att) => $this->attachmentData($att))->values(),
                    'timestamp' => $decidedAt ? $decidedAt->getTimestamp() : 0,
                ];
            });

            $items = $items->concat($lateItems);
        }

        // 2. Koreksi Absensi
        if ($type === 'all' || $type === 'correction') {
            $query = AttendanceCorrection::query()
                ->with(['employee.department:id,name', 'session.assignment', 'decidedBy:id,name', 'attachments'])
                ->whereIn('employee_id', $employeeIds);

            if ($status !== 'all') {
                $query->where('status', $status);
            } else {
                $query->whereIn('status', ['APPROVED', 'REJECTED', 'CANCELLED']);
            }

            if ($from && $to) {
                $query->whereHas('session.assignment', fn ($sq) => $sq->whereBetween('work_date', [$from, $to]));
            }

            if ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
                        ->orWhere('reason', 'like', "%{$q}%")
                        ->orWhere('decision_reason', 'like', "%{$q}%");
                });
            }

            $corrItems = $query->get()->map(function (AttendanceCorrection $item) {
                $decidedAt = $item->decided_at ?? $item->updated_at;
                $inLocal = $item->requested_check_in_at ? WorkTime::local($item->requested_check_in_at)?->format('H:i') : '-';
                $outLocal = $item->requested_check_out_at ? WorkTime::local($item->requested_check_out_at)?->format('H:i') : '-';

                return [
                    'id' => $item->id,
                    'type' => 'correction',
                    'type_label' => 'Koreksi Absensi',
                    'employee' => [
                        'id' => $item->employee->id,
                        'name' => $item->employee->name,
                        'code' => $item->employee->code,
                        'avatar_url' => $item->employee->avatar_url,
                        'department' => $item->employee->department?->name ?? '-',
                    ],
                    'work_date' => $item->session?->assignment?->work_date,
                    'shift' => $item->session ? ($item->session->assignment?->template_name.' · sesi '.$item->session->seq) : '-',
                    'detail' => "Masuk: {$inLocal} · Pulang: {$outLocal}",
                    'reason' => $item->reason,
                    'status' => $item->status,
                    'decided_by' => $item->decidedBy?->name ?? 'Sistem / Admin',
                    'decided_at' => WorkTime::iso($decidedAt),
                    'decided_at_formatted' => WorkTime::local($decidedAt)?->format('d/m/Y H:i'),
                    'decision_reason' => $item->decision_reason ?? '-',
                    'attachments' => $item->attachments->map(fn ($att) => $this->attachmentData($att))->values(),
                    'timestamp' => $decidedAt ? $decidedAt->getTimestamp() : 0,
                ];
            });

            $items = $items->concat($corrItems);
        }

        // 3. Lembur & SPL
        if ($type === 'all' || $type === 'overtime') {
            $query = OvertimeOrder::query()
                ->with(['employee.department:id,name', 'session.assignment', 'decisionMaker:id,name'])
                ->whereIn('employee_id', $employeeIds);

            if ($status !== 'all') {
                $query->where('status', $status);
            } else {
                $query->whereIn('status', ['APPROVED', 'REJECTED', 'CANCELLED']);
            }

            if ($from && $to) {
                $query->whereBetween('work_date', [$from, $to]);
            }

            if ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhere('decision_reason', 'like', "%{$q}%");
                });
            }

            $otItems = $query->get()->map(function (OvertimeOrder $item) {
                $decidedAt = $item->decided_at ?? $item->updated_at;
                $startLocal = $item->start_at ? WorkTime::local($item->start_at)?->format('H:i') : '-';
                $endLocal = $item->end_at ? WorkTime::local($item->end_at)?->format('H:i') : '-';

                return [
                    'id' => $item->id,
                    'type' => 'overtime',
                    'type_label' => 'Lembur & SPL',
                    'employee' => [
                        'id' => $item->employee->id,
                        'name' => $item->employee->name,
                        'code' => $item->employee->code,
                        'avatar_url' => $item->employee->avatar_url,
                        'department' => $item->employee->department?->name ?? '-',
                    ],
                    'work_date' => $item->work_date,
                    'shift' => $item->title,
                    'detail' => "SPL {$startLocal}–{$endLocal} ({$item->planned_minutes}m)",
                    'reason' => $item->notes ?? $item->title,
                    'status' => $item->status,
                    'decided_by' => $item->decisionMaker?->name ?? 'Sistem / Admin',
                    'decided_at' => WorkTime::iso($decidedAt),
                    'decided_at_formatted' => WorkTime::local($decidedAt)?->format('d/m/Y H:i'),
                    'decision_reason' => $item->decision_reason ?? '-',
                    'attachments' => [],
                    'timestamp' => $decidedAt ? $decidedAt->getTimestamp() : 0,
                ];
            });

            $items = $items->concat($otItems);
        }

        // 4. Tukar Shift
        if ($type === 'all' || $type === 'shift_exchange') {
            $query = ShiftExchangeRequest::query()
                ->with(['employee.department:id,name', 'targetEmployee.department:id,name', 'shiftAssignment.sessions', 'targetAssignment.sessions', 'decidedBy:id,name'])
                ->where(function ($sub) use ($employeeIds) {
                    $sub->whereIn('employee_id', $employeeIds)
                        ->orWhereIn('target_employee_id', $employeeIds);
                });

            if ($status !== 'all') {
                $query->where('status', $status);
            } else {
                $query->whereIn('status', ['APPROVED', 'REJECTED', 'CANCELLED']);
            }

            if ($from && $to) {
                $query->whereHas('shiftAssignment', fn ($sa) => $sa->whereBetween('work_date', [$from, $to]));
            }

            if ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
                        ->orWhereHas('targetEmployee', fn ($te) => $te->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
                        ->orWhere('reason', 'like', "%{$q}%")
                        ->orWhere('decision_reason', 'like', "%{$q}%");
                });
            }

            $exchangeItems = $query->get()->map(function (ShiftExchangeRequest $item) {
                $decidedAt = $item->decided_at ?? $item->updated_at;
                $sourceDate = $item->shiftAssignment?->work_date ?? '-';
                $targetName = $item->targetEmployee?->name ?? 'Pengganti';
                $modeLabel = $item->swap_mode === 'direct' ? 'Tukar Jadwal' : 'Pelimpahan Shift';

                return [
                    'id' => $item->id,
                    'type' => 'shift_exchange',
                    'type_label' => 'Tukar Shift',
                    'employee' => [
                        'id' => $item->employee->id,
                        'name' => $item->employee->name,
                        'code' => $item->employee->code,
                        'avatar_url' => $item->employee->avatar_url,
                        'department' => $item->employee->department?->name ?? '-',
                    ],
                    'work_date' => $sourceDate,
                    'shift' => "{$modeLabel} ke {$targetName}",
                    'detail' => $item->shiftAssignment ? ($item->shiftAssignment->template_name." ({$sourceDate})") : '-',
                    'reason' => $item->reason,
                    'status' => $item->status,
                    'decided_by' => $item->decidedBy?->name ?? 'Sistem / Admin',
                    'decided_at' => WorkTime::iso($decidedAt),
                    'decided_at_formatted' => WorkTime::local($decidedAt)?->format('d/m/Y H:i'),
                    'decision_reason' => $item->decision_reason ?? '-',
                    'attachments' => [],
                    'timestamp' => $decidedAt ? $decidedAt->getTimestamp() : 0,
                ];
            });

            $items = $items->concat($exchangeItems);
        }

        $sorted = $items->sortByDesc('timestamp')->values();
        $total = $sorted->count();
        $sliced = $sorted->slice(($page - 1) * $perPage, $perPage)->values();
        $lastPage = (int) max(1, ceil($total / $perPage));

        return response()->json([
            'data' => $sliced,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
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
