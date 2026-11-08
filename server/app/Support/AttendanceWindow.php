<?php

namespace App\Support;

use App\Models\ShiftSession;
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
        return self::effectiveWorkEnd($session)->addMinutes(60);
    }
}
