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
