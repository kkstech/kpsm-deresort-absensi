<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\ShiftSession;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AutoCheckoutAttendance
{
    public function handle(): int
    {
        $now = WorkTime::now();
        $sessionIds = ShiftSession::query()
            ->whereNull('retired_at')
            ->whereHas('assignment', fn ($query) => $query->whereIn('status', ['PUBLISHED', 'IN_PROGRESS']))
            ->whereHas('attendanceRecord', fn ($query) => $query->whereNotNull('check_in_at')->whereNull('check_out_at')->whereNull('provisional_out_at'))
            ->pluck('id');

        $closed = 0;
        foreach ($sessionIds as $sessionId) {
            $closed += DB::transaction(function () use ($sessionId, $now) {
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
                    || $record->check_in_at === null
                    || $record->check_out_at !== null || $record->provisional_out_at !== null) {
                    return 0;
                }

                $closeAt = $this->closeAt($session, $record);
                if ($closeAt === null || $closeAt->gt($now)) {
                    return 0;
                }

                try {
                    AttendancePeriod::assertOpen($session->assignment->work_date);
                } catch (BusinessRuleException) {
                    return 0;
                }

                $record->update([
                    'provisional_out_at' => $closeAt,
                    'status' => 'PROVISIONAL',
                    'needs_review' => true,
                    'review_reason' => 'AUTO_CHECKOUT',
                    'verified_minutes' => 0,
                ]);
                $session->assignment->syncAttendanceStatus();
                app(CalculateCycleAllowance::class)->refresh($session->assignment);
                Audit::log('attendance.auto_checkout', $record);

                return 1;
            });
        }

        $ended = ShiftAssignment::query()->with('sessions')
            ->whereIn('status', ['PUBLISHED', 'IN_PROGRESS'])
            ->whereHas('sessions', fn ($query) => $query->where('end_at', '<=', $now))
            ->get()
            ->filter(fn (ShiftAssignment $assignment) => $assignment->sessions->isNotEmpty()
                && $assignment->sessions->every(fn (ShiftSession $session) => $session->end_at->lte($now)));

        foreach ($ended as $assignment) {
            try {
                AttendancePeriod::assertOpen($assignment->work_date);
            } catch (BusinessRuleException) {
                continue;
            }
            $before = $assignment->status;
            $assignment->syncAttendanceStatus();
            if ($assignment->fresh()->status === 'COMPLETED' && $before !== 'COMPLETED') {
                Audit::log('schedule.completed', $assignment, ['status' => $before]);
            }
        }

        return $closed;
    }

    /** Menutup siklus sebelumnya sebelum menerima check-in berikutnya. */
    public function closeExpiredBeforeNextCheckin(Employee $employee, int $targetSessionId, CarbonImmutable $receivedAt): ?string
    {
        $open = AttendanceRecord::query()->with('session.assignment')
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in_at')->whereNull('check_out_at')->whereNull('provisional_out_at')
            ->orderBy('id')->get();

        foreach ($open as $record) {
            $session = $record->session;
            if (! $session || $session->id === $targetSessionId) {
                continue;
            }
            if ($session->end_at->gt($receivedAt)) {
                return 'OPEN_SESSION_EXISTS';
            }
            try {
                AttendancePeriod::assertOpen($session->assignment->work_date);
            } catch (BusinessRuleException) {
                return 'PREVIOUS_PERIOD_LOCKED';
            }

            $session = ShiftSession::query()->with('assignment')->lockForUpdate()->find($session->id);
            $record = AttendanceRecord::query()->lockForUpdate()->find($record->id);
            if (! $session || ! $record || $record->check_out_at || $record->provisional_out_at) {
                continue;
            }
            $record->update([
                'provisional_out_at' => $session->end_at,
                'status' => 'PROVISIONAL',
                'needs_review' => true,
                'review_reason' => 'AUTO_CHECKOUT',
                'verified_minutes' => 0,
            ]);
            $session->assignment->syncAttendanceStatus();
            app(CalculateCycleAllowance::class)->refresh($session->assignment);
            Audit::log('attendance.auto_checkout', $record);
        }

        return null;
    }

    private function closeAt(ShiftSession $session, AttendanceRecord $record): ?CarbonImmutable
    {
        if ((int) $session->assignment->rules_version === 0) {
            return $session->end_at->addMinutes(config('attendance.auto_checkout_after_minutes'));
        }

        if ($session->assignment->template_type === 'regular') {
            return $record->check_in_at->addMinutes(13 * 60);
        }

        $sessions = $session->assignment->sessions()->with('attendanceRecord')->get();
        $cycleStart = $sessions->map(fn (ShiftSession $candidate) => $candidate->attendanceRecord?->check_in_at)
            ->filter()->sortBy(fn ($checkIn) => $checkIn->getTimestamp())->first() ?? $record->check_in_at;
        $cycleDeadline = $cycleStart->addHours(24);

        return $session->end_at->lt($cycleDeadline) ? $session->end_at : $cycleDeadline;
    }
}
