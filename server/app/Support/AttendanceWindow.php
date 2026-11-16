<?php

namespace App\Support;

use App\Models\AttendanceRecord;
use App\Models\ShiftSession;
use App\Models\SiteSetting;
use Carbon\CarbonImmutable;

class AttendanceWindow
{
    public static function effectiveWorkEnd(ShiftSession $session): CarbonImmutable
    {
        $approvedOvertimeEnd = $session->overtimeOrders
            ->filter(fn ($order) => $order->status === 'APPROVED' && $order->consent_status === 'ACCEPTED')
            ->max(fn ($order) => $order->end_at->getTimestamp());

        if ($approvedOvertimeEnd === null || $approvedOvertimeEnd <= $session->end_at->getTimestamp()) {
            return $session->end_at;
        }

        return CarbonImmutable::createFromTimestamp($approvedOvertimeEnd, 'UTC');
    }

    /**
     * Scheduled start of the attendance cycle the session belongs to.
     * Regular cycles have one session, so this is the session's own start_at.
     * Split/special cycles use the scheduled start of the first active (non-retired) session in the assignment,
     * never the time the employee actually pressed check-in.
     */
    public static function scheduledCycleStart(ShiftSession $session): CarbonImmutable
    {
        $first = $session->assignment->sessions()->orderBy('start_at')->value('start_at');
        $first = $first ? CarbonImmutable::parse($first, 'UTC') : null;

        return $first && $first->lt($session->start_at) ? $first : $session->start_at;
    }

    /**
     * Rules v4: the system closes the session automatically 13 hours after the scheduled cycle start.
     * The deadline is never earlier than the session's scheduled end (cycles may legally exceed 13 hours)
     * and is extended to the end of an approved overtime order the employee accepted.
     * The record argument is kept for caller compatibility; actual check-in time no longer matters.
     */
    public static function automaticCheckoutAt(ShiftSession $session, ?AttendanceRecord $record = null): CarbonImmutable
    {
        $deadline = static::scheduledCycleStart($session)->addHours(13)->max($session->end_at);
        $approvedOvertimeEnd = $session->overtimeOrders
            ->filter(fn ($order) => $order->status === 'APPROVED' && $order->consent_status === 'ACCEPTED')
            ->max(fn ($order) => $order->end_at->getTimestamp());

        if ($approvedOvertimeEnd !== null) {
            $deadline = $deadline->max(CarbonImmutable::createFromTimestamp($approvedOvertimeEnd, 'UTC'));
        }

        return $deadline;
    }

    public static function checkoutDeadline(ShiftSession $session): CarbonImmutable
    {
        $setting = SiteSetting::current();
        $cutoff = WorkTime::at(WorkTime::local($session->end_at)->toDateString(), $setting->checkout_cutoff_time ?? '23:59');
        $nextStart = ShiftSession::query()->whereNull('retired_at')->where('id', '!=', $session->id)
            ->where('start_at', '>=', $session->end_at)
            ->whereHas('assignment', fn ($query) => $query->where('employee_id', $session->assignment->employee_id)->whereNotIn('status', ['DRAFT', 'CANCELLED']))
            ->orderBy('start_at')->value('start_at');

        return $nextStart ? $cutoff->min(CarbonImmutable::parse($nextStart, 'UTC')) : $cutoff;
    }
}
