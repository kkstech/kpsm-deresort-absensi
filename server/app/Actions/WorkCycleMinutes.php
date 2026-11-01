<?php

namespace App\Actions;

use App\Models\AttendanceRecord;
use App\Models\ShiftAssignment;
use App\Support\WorkTime;

class WorkCycleMinutes
{
    public function forAssignment(ShiftAssignment $assignment): int
    {
        $assignment->loadMissing('sessions.attendanceRecord');
        $minutes = 0;

        foreach ($assignment->sessions as $session) {
            $record = $session->attendanceRecord;
            if (! $record || $record->needs_review) {
                continue;
            }

            if ($record->check_out_at) {
                $minutes += max(0, (int) $record->verified_minutes);

                continue;
            }

            if ($record->check_in_at && ! $record->provisional_out_at) {
                $minutes += AttendanceRecord::paidMinutes($session, $record->check_in_at, WorkTime::now());
            }
        }

        return $minutes;
    }
}
