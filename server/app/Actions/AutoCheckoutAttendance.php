<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ShiftSession;
use App\Support\Audit;
use App\Support\WorkTime;
use Illuminate\Support\Facades\DB;

class AutoCheckoutAttendance
{
    public function handle(): int
    {
        $cutoff = WorkTime::now()->subMinutes(config('attendance.auto_checkout_after_minutes'));
        $sessionIds = ShiftSession::query()
            ->where('end_at', '<=', $cutoff)
            ->whereHas('assignment', fn ($query) => $query->whereIn('status', ['PUBLISHED', 'IN_PROGRESS']))
            ->whereHas('attendanceRecord', fn ($query) => $query->whereNotNull('check_in_at')->whereNull('check_out_at')->whereNull('provisional_out_at'))
            ->pluck('id');

        $closed = 0;
        foreach ($sessionIds as $sessionId) {
            $closed += DB::transaction(function () use ($sessionId, $cutoff) {
                $employeeId = DB::table('shift_sessions')
                    ->join('shift_assignments', 'shift_assignments.id', '=', 'shift_sessions.shift_assignment_id')
                    ->where('shift_sessions.id', $sessionId)
                    ->value('shift_assignments.employee_id');
                if (! $employeeId) {
                    return 0;
                }

                Employee::query()->lockForUpdate()->findOrFail($employeeId);
                $session = ShiftSession::query()->with('assignment')->lockForUpdate()->find($sessionId);
                $record = AttendanceRecord::query()->where('shift_session_id', $sessionId)->lockForUpdate()->first();

                if (! $session || ! $record || $session->assignment->employee_id !== $employeeId
                    || ! in_array($session->assignment->status, ['PUBLISHED', 'IN_PROGRESS'], true)
                    || $session->end_at->gt($cutoff) || $record->check_in_at === null
                    || $record->check_out_at !== null || $record->provisional_out_at !== null) {
                    return 0;
                }

                try {
                    AttendancePeriod::assertOpen($session->assignment->work_date);
                } catch (BusinessRuleException) {
                    return 0;
                }

                $record->update([
                    'provisional_out_at' => $session->end_at,
                    'status' => 'PROVISIONAL',
                    'needs_review' => true,
                    'review_reason' => 'AUTO_CHECKOUT',
                    'verified_minutes' => 0,
                ]);
                $session->assignment->syncAttendanceStatus();
                Audit::log('attendance.auto_checkout', $record);

                return 1;
            });
        }

        return $closed;
    }
}
