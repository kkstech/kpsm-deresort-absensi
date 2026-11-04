<?php

namespace App\Support;

use App\Models\LatePermission;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;

class WorkflowNotifier
{
    public function leaveRequested(LeaveRequest $leave): void
    {
        $leave->loadMissing('employee');
        $this->sendTo(
            $this->approversFor($leave->approval_tier),
            'leave_requested',
            'Pengajuan cuti baru',
            $leave->employee->name.' mengajukan cuti '.$leave->work_date.($leave->end_date ? ' sampai '.$leave->end_date : '').'.',
            ['tab' => 'Pengajuan', 'request_type' => 'leave', 'request_id' => $leave->id],
        );
    }

    public function leaveDecision(LeaveRequest $leave): void
    {
        $leave->loadMissing('employee.user');
        $approved = $leave->status === 'APPROVED';
        $this->sendTo(
            collect([$leave->employee?->user])->filter(),
            'leave_decision',
            $approved ? 'Pengajuan cuti disetujui' : 'Pengajuan cuti ditolak',
            'Pengajuan cuti '.$leave->work_date.' '.($approved ? 'disetujui' : 'ditolak').'.'.($leave->decision_reason ? ' '.$leave->decision_reason : ''),
            ['tab' => 'Pengajuan', 'request_type' => 'leave', 'request_id' => $leave->id, 'status' => $leave->status],
            true,
        );
    }

    public function leaveCancelled(LeaveRequest $leave): void
    {
        $leave->loadMissing('employee.user');
        $date = $leave->work_date.($leave->end_date ? ' sampai '.$leave->end_date : '');
        $this->sendTo(
            collect([$leave->employee?->user])->filter(),
            'leave_cancelled',
            'Pengajuan cuti dibatalkan',
            'Pengajuan cuti '.$date.' dibatalkan oleh HR.'.($leave->decision_reason ? ' '.$leave->decision_reason : ''),
            ['tab' => 'Pengajuan', 'request_type' => 'leave', 'request_id' => $leave->id, 'status' => $leave->status],
            true,
        );
    }

    public function permissionRequested(LatePermission $permission): void
    {
        $permission->loadMissing('employee', 'session.assignment');
        $workDate = $permission->session?->assignment?->work_date ?? 'tanggal kerja';
        $approvers = $this->approversFor($permission->approval_tier)
            ->reject(fn (User $user) => $user->id === $permission->requester_id);
        $this->sendTo(
            $approvers,
            'permission_requested',
            'Pengajuan izin baru',
            $permission->employee->name.' mengajukan izin untuk '.$workDate.'.',
            ['tab' => 'Pengajuan', 'request_type' => 'permission', 'request_id' => $permission->id],
        );
    }

    public function permissionDecision(LatePermission $permission): void
    {
        $permission->loadMissing('employee.user', 'session.assignment');
        $approved = $permission->status === 'APPROVED';
        $workDate = $permission->session?->assignment?->work_date ?? 'tanggal kerja';
        $this->sendTo(
            collect([$permission->employee?->user])->filter(),
            'permission_decision',
            $approved ? 'Pengajuan izin disetujui' : 'Pengajuan izin ditolak',
            'Pengajuan izin untuk '.$workDate.' '.($approved ? 'disetujui' : 'ditolak').'.'.($permission->decision_reason ? ' '.$permission->decision_reason : ''),
            ['tab' => 'Pengajuan', 'request_type' => 'permission', 'request_id' => $permission->id, 'status' => $permission->status],
            true,
        );
    }

    public function overtimeRecommendation(array $recommendation): void
    {
        $employee = $recommendation['employee'];
        $context = [
            'tab' => 'Pengajuan',
            'request_type' => 'overtime_recommendation',
            'employee_id' => $employee['id'],
            'shift_assignment_id' => $recommendation['shift_assignment_id'],
            'shift_session_id' => $recommendation['shift_session_id'],
            'work_date' => $recommendation['work_date'],
            'shift_end_time' => $recommendation['shift_end_time'],
            'shift_end_day_offset' => $recommendation['shift_end_day_offset'],
            'suggested_start_time' => $recommendation['suggested_start_time'],
            'suggested_end_time' => $recommendation['suggested_end_time'],
            'start_day_offset' => $recommendation['start_day_offset'],
            'end_day_offset' => $recommendation['end_day_offset'],
            'is_cross_day' => $recommendation['is_cross_day'],
        ];

        $users = User::query()->whereIn('role', ['admin', 'super_admin'])->where('is_active', true)->get();
        foreach ($users as $user) {
            $alreadyNotified = $user->notifications()
                ->where('data->kind', 'overtime_recommendation')
                ->where('data->context->shift_session_id', $recommendation['shift_session_id'])
                ->exists();
            if ($alreadyNotified) {
                continue;
            }

            $user->notify(new WorkflowNotification(
                'overtime_recommendation',
                'Rekomendasi lembur',
                $employee['name'].' · shift '.$recommendation['shift'].' berakhir pukul '.$recommendation['shift_end_time'].'. Usulan SPL '.$recommendation['suggested_start_time'].'–'.$recommendation['suggested_end_time'].' (2 jam). Buka website > Persetujuan.',
                $context,
                true,
            ));
        }
    }

    private function sendTo(Collection $users, string $kind, string $title, string $body, array $context, bool $sendPush = false): void
    {
        foreach ($users as $user) {
            $user->notify(new WorkflowNotification($kind, $title, $body, $context, $sendPush));
        }
    }

    private function approversFor(string $approvalTier): Collection
    {
        $roles = $approvalTier === 'super_admin' ? ['super_admin'] : ['admin', 'super_admin'];

        return User::query()->whereIn('role', $roles)->where('is_active', true)->get();
    }
}
