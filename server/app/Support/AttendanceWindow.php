<?php

namespace App\Support;

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
