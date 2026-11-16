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

    public static function automaticCheckoutAt(ShiftSession $session, AttendanceRecord $record): CarbonImmutable
    {
        $rulesVersion = (int) ($session->rules_version ?: $session->assignment->rules_version);
        if ($rulesVersion >= 5) {
            return $session->start_at->addHours(13);
        }

        $cycleStart = $record->check_in_at;
        if ($session->assignment->template_type === 'special') {
            $cycleStart = $session->assignment->sessions()->with('attendanceRecord')->get()
                ->map(fn (ShiftSession $candidate) => $candidate->attendanceRecord?->check_in_at)
                ->filter()
                ->sortBy(fn (CarbonImmutable $checkIn) => $checkIn->getTimestamp())
                ->first() ?? $record->check_in_at;
        }

        $deadline = $cycleStart->addHours(13);
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
