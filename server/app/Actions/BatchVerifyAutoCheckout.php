<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\ShiftSession;
use App\Support\AttendanceWindow;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Batch verification for attendance records closed automatically by system (provisional/auto checkout).
 * Sets confirmed checkout time, clears needs_review flag, marks status VERIFIED,
 * recomputes verified_minutes, refreshes schedule assignment status, overtime, and cycle allowance.
 */
class BatchVerifyAutoCheckout
{
    public const AUDIT_ACTION = 'attendance.checkout.batch_verified';

    /**
     * @return array{eligible: list<array<string, mixed>>, skipped: list<array<string, mixed>>, applied: int}
     */
    public function handle(?string $from = null, ?string $to = null, string $checkoutTimeMode = 'scheduled', bool $apply = false, ?int $actorId = null): array
    {
        $eligible = [];
        $skipped = [];
        $applied = 0;

        foreach ($this->candidates($from, $to) as $recordId) {
            $outcome = $apply
                ? DB::transaction(fn () => $this->process($recordId, $checkoutTimeMode, true, $actorId))
                : $this->process($recordId, $checkoutTimeMode, false, $actorId);

            if ($outcome === null) {
                continue;
            }

            if ($outcome['skip_reason'] !== null) {
                $skipped[] = $outcome;

                continue;
            }

            $eligible[] = $outcome;
            if ($apply) {
                $applied++;
            }
        }

        return ['eligible' => $eligible, 'skipped' => $skipped, 'applied' => $applied];
    }

    /** @return list<int> */
    private function candidates(?string $from, ?string $to): array
    {
        return AttendanceRecord::query()
            ->where('needs_review', true)
            ->where(function ($query) {
                $query->where('review_reason', 'AUTO_CHECKOUT')
                    ->orWhereNotNull('provisional_out_at')
                    ->orWhere('checkout_source', AttendanceRecord::CHECKOUT_SOURCE_SYSTEM_AUTO);
            })
            ->whereNotNull('check_in_at')
            ->whereHas('session.assignment', function ($query) use ($from, $to) {
                if ($from) {
                    $query->where('work_date', '>=', $from);
                }
                if ($to) {
                    $query->where('work_date', '<=', $to);
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function process(int $recordId, string $checkoutTimeMode, bool $apply, ?int $actorId): ?array
    {
        $query = AttendanceRecord::query()->whereKey($recordId);
        $record = $apply ? $query->lockForUpdate()->first() : $query->first();

        if (! $record || ! $record->needs_review) {
            return null;
        }

        $session = ShiftSession::query()->with(['assignment.employee', 'overtimeOrders'])->find($record->shift_session_id);
        $row = $this->row($record, $session);

        $skip = $this->skipReason($record, $session);
        if ($skip !== null) {
            return [...$row, 'skip_reason' => $skip];
        }

        $confirmedOut = $this->resolveCheckoutTime($session, $record, $checkoutTimeMode);
        $row['confirmed_out'] = WorkTime::local($confirmedOut)->format('Y-m-d H:i');

        $verifiedMinutes = AttendanceRecord::paidMinutes($session, $record->check_in_at, $confirmedOut);
        $row['verified_minutes'] = $verifiedMinutes;

        if (! $apply) {
            return [...$row, 'skip_reason' => null];
        }

        $before = $this->snapshot($record);
        $checkoutSource = ($record->check_out_at !== null && $record->check_out_at->equalTo($confirmedOut) && $record->checkout_source)
            ? $record->checkout_source
            : AttendanceRecord::CHECKOUT_SOURCE_HR_CORRECTION;

        $record->update([
            'check_out_at' => $confirmedOut,
            'provisional_out_at' => null,
            'checkout_source' => $checkoutSource,
            'auto_checkout_at' => $record->auto_checkout_at ?? $record->provisional_out_at,
            'status' => 'VERIFIED',
            'needs_review' => false,
            'review_reason' => null,
            'verified_minutes' => $verifiedMinutes,
            'verified_at' => WorkTime::now(),
            'verified_by' => $actorId,
        ]);

        AttendancePeriod::invalidateFinalization($session->assignment->work_date, self::AUDIT_ACTION);
        $session->assignment->syncAttendanceStatus();
        app(ManageOvertime::class)->calculateRealization($session, $record);
        app(CalculateCycleAllowance::class)->refresh($session->assignment);
        Audit::log(self::AUDIT_ACTION, $record, $before, [
            ...$this->snapshot($record->fresh()),
            'reason' => 'Batch verified auto-checkout records under current rules.',
            'checkout_time_mode' => $checkoutTimeMode,
        ]);

        return [...$row, 'skip_reason' => null];
    }

    private function resolveCheckoutTime(ShiftSession $session, AttendanceRecord $record, string $checkoutTimeMode): CarbonImmutable
    {
        if ($record->check_out_at !== null) {
            return $record->check_out_at;
        }

        if ($checkoutTimeMode === 'auto') {
            $candidate = $record->provisional_out_at ?? $record->auto_checkout_at;
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return AttendanceWindow::effectiveWorkEnd($session);
    }

    private function skipReason(AttendanceRecord $record, ?ShiftSession $session): ?string
    {
        return match (true) {
            ! $session => 'SESSION_MISSING',
            $session->retired_at !== null => 'SESSION_RETIRED',
            $session->assignment->status === 'CANCELLED' => 'SCHEDULE_CANCELLED',
            $record->status === 'VERIFIED' || $record->verified_at !== null => 'ALREADY_VERIFIED',
            $record->check_in_at === null => 'NO_CHECKIN',
            $this->periodLocked($session->assignment->work_date) => 'PERIOD_LOCKED',
            default => null,
        };
    }

    private function periodLocked(string $workDate): bool
    {
        try {
            AttendancePeriod::assertOpen($workDate);

            return false;
        } catch (BusinessRuleException) {
            return true;
        }
    }

    /** @return array<string, mixed> */
    private function row(AttendanceRecord $record, ?ShiftSession $session): array
    {
        $employee = $session?->assignment?->employee;

        return [
            'record_id' => $record->id,
            'work_date' => $session?->assignment?->work_date,
            'employee' => $employee ? trim(($employee->code ?? '').' '.($employee->name ?? '')) : (string) $record->employee_id,
            'session' => $session ? WorkTime::local($session->start_at)->format('H:i').'–'.WorkTime::local($session->end_at)->format('H:i') : null,
            'check_in' => $record->check_in_at ? WorkTime::local($record->check_in_at)->format('Y-m-d H:i') : null,
            'confirmed_out' => null,
            'verified_minutes' => null,
            'skip_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(AttendanceRecord $record): array
    {
        return $record->only([
            'check_in_at', 'check_out_at', 'provisional_out_at', 'checkout_source', 'auto_checkout_at',
            'status', 'needs_review', 'review_reason', 'verified_minutes', 'verified_overtime_minutes', 'verified_at', 'verified_by',
        ]);
    }
}
