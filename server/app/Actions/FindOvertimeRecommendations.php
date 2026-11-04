<?php

namespace App\Actions;

use App\Models\OvertimeOrder;
use App\Models\ShiftAssignment;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FindOvertimeRecommendations
{
    public function execute(CarbonImmutable $from, CarbonImmutable $to, ?Builder $visibleEmployees = null): Collection
    {
        $activeOvertimeSessionIds = OvertimeOrder::query()
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->whereNotNull('shift_session_id')
            ->pluck('shift_session_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $assignments = ShiftAssignment::query()
            ->with(['employee.department', 'employee.position', 'sessions.attendanceRecord'])
            ->where('status', '!=', 'CANCELLED')
            ->when($visibleEmployees, fn (Builder $query) => $query->whereIn('employee_id', $visibleEmployees->select('id')))
            ->whereHas('employee', fn (Builder $query) => $query->where('status', 'active'))
            ->whereHas('sessions', fn (Builder $query) => $query->where('end_at', '>', $from)->where('end_at', '<=', $to))
            ->get()
            ->sortBy(fn (ShiftAssignment $assignment) => $assignment->sessions
                ->max(fn ($session) => $session->end_at->getTimestamp()) ?? 0);

        return $assignments->map(function (ShiftAssignment $assignment) use ($activeOvertimeSessionIds, $from, $to) {
            $lastSession = $assignment->sessions
                ->sortBy(fn ($session) => $session->end_at->getTimestamp())
                ->last();
            if (! $lastSession || $lastSession->end_at->lte($from) || $lastSession->end_at->gt($to)) {
                return null;
            }

            $attendance = $lastSession->attendanceRecord;
            if (! $attendance?->check_in_at || $attendance->check_out_at || $attendance->provisional_out_at
                || in_array((int) $lastSession->id, $activeOvertimeSessionIds, true)) {
                return null;
            }

            $suggestedStart = $lastSession->end_at->setTimezone(WorkTime::tz());
            $suggestedEnd = $suggestedStart->addHours(2);
            $workDate = CarbonImmutable::parse($assignment->work_date, WorkTime::tz())->startOfDay();
            $startDayOffset = (int) $workDate->diffInDays($suggestedStart->startOfDay(), false);
            $endDayOffset = (int) $workDate->diffInDays($suggestedEnd->startOfDay(), false);

            return [
                'employee' => [
                    'id' => $assignment->employee_id,
                    'name' => $assignment->employee?->name,
                    'department' => $assignment->employee?->department?->name,
                    'position' => $assignment->employee?->position?->name,
                ],
                'shift_assignment_id' => $assignment->id,
                'shift_session_id' => $lastSession->id,
                'work_date' => $assignment->work_date,
                'shift' => $assignment->template_name,
                'shift_end_time' => $suggestedStart->format('H:i'),
                'shift_end_day_offset' => $startDayOffset,
                'suggested_start_time' => $suggestedStart->format('H:i'),
                'suggested_end_time' => $suggestedEnd->format('H:i'),
                'start_day_offset' => $startDayOffset,
                'end_day_offset' => $endDayOffset,
                'is_cross_day' => $endDayOffset > $startDayOffset,
            ];
        })->filter()->values();
    }
}
