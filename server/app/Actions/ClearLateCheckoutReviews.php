<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceEvent;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\ShiftSession;
use App\Support\AttendanceWindow;
use App\Support\Audit;
use App\Support\WorkTime;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup: clears the review flag on employee self checkouts that were flagged CHECKOUT_LATE
 * by the old grace rule but fall within the current rule (scheduled cycle start + 13h, or approved overtime end).
 * The attendance record and its raw events are kept; only needs_review/review_reason change and
 * verified_minutes is recomputed exactly like an accepted self checkout.
 */
class ClearLateCheckoutReviews
{
    public const AUDIT_ACTION = 'attendance.late_checkout_review_cleared';

    /**
     * @return array{eligible: list<array<string, mixed>>, skipped: list<array<string, mixed>>, applied: int}
     */
    public function handle(?string $from = null, ?string $to = null, bool $apply = false): array
    {
        $eligible = [];
        $skipped = [];
        $applied = 0;

        foreach ($this->candidates($from, $to) as $recordId) {
            $outcome = $apply
                ? DB::transaction(fn () => $this->process($recordId, true))
                : $this->process($recordId, false);
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
            ->where('review_reason', 'CHECKOUT_LATE')
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
    private function process(int $recordId, bool $apply): ?array
    {
        $query = AttendanceRecord::query()->whereKey($recordId);
        $record = $apply ? $query->lockForUpdate()->first() : $query->first();
        if (! $record || ! $record->needs_review || $record->review_reason !== 'CHECKOUT_LATE') {
            return null;
        }
        $session = ShiftSession::query()->with(['assignment.employee', 'overtimeOrders'])->find($record->shift_session_id);
        $row = $this->row($record, $session);

        $skip = $this->skipReason($record, $session);
        if ($skip !== null) {
            return [...$row, 'skip_reason' => $skip];
        }

        $deadline = AttendanceWindow::automaticCheckoutAt($session, $record);
        $row['deadline'] = WorkTime::local($deadline)->format('Y-m-d H:i');
        if ($record->check_out_at->gt($deadline)) {
            return [...$row, 'skip_reason' => 'CHECKOUT_AFTER_DEADLINE'];
        }

        $verifiedMinutes = AttendanceRecord::paidMinutes($session, $record->check_in_at, $record->check_out_at);
        $row['verified_minutes'] = $verifiedMinutes;
        if (! $apply) {
            return [...$row, 'skip_reason' => null];
        }

        $before = $this->snapshot($record);
        $record->update([
            'needs_review' => false,
            'review_reason' => null,
            'status' => 'COMPLETE',
            'verified_minutes' => $verifiedMinutes,
        ]);
        AttendancePeriod::invalidateFinalization($session->assignment->work_date, self::AUDIT_ACTION);
        $session->assignment->syncAttendanceStatus();
        app(ManageOvertime::class)->calculateRealization($session, $record);
        app(CalculateCycleAllowance::class)->refresh($session->assignment);
        Audit::log(self::AUDIT_ACTION, $record, $before, [
            ...$this->snapshot($record->fresh()),
            'reason' => 'Self checkout within scheduled start + 13h; review no longer required under rules v4.',
            'deadline' => WorkTime::iso($deadline),
        ]);

        return [...$row, 'skip_reason' => null];
    }

    private function skipReason(AttendanceRecord $record, ?ShiftSession $session): ?string
    {
        return match (true) {
            ! $session => 'SESSION_MISSING',
            $session->retired_at !== null => 'SESSION_RETIRED',
            $session->assignment->status === 'CANCELLED' => 'SCHEDULE_CANCELLED',
            $record->status === 'VERIFIED' || $record->verified_at !== null => 'ALREADY_VERIFIED',
            $record->check_in_at === null || $record->check_out_at === null => 'NO_CHECKOUT',
            $record->provisional_out_at !== null || $record->auto_checkout_at !== null => 'AUTO_CHECKOUT_INVOLVED',
            $record->checkout_source !== AttendanceRecord::CHECKOUT_SOURCE_EMPLOYEE => 'NOT_SELF_CHECKOUT',
            ! $this->onlyLateCheckoutReviewEvents($record) => 'OTHER_REVIEW_EVENT',
            $this->periodLocked($session->assignment->work_date) => 'PERIOD_LOCKED',
            default => null,
        };
    }

    /** The checkout event must be the CHECKOUT_LATE one, and no other event of the session may carry a different review reason. */
    private function onlyLateCheckoutReviewEvents(AttendanceRecord $record): bool
    {
        $checkout = $record->check_out_event_id ? AttendanceEvent::query()->find($record->check_out_event_id) : null;
        if (! $checkout || $checkout->type !== 'CHECK_OUT' || $checkout->result !== 'REVIEW_REQUIRED' || $checkout->reason_code !== 'CHECKOUT_LATE') {
            return false;
        }

        return ! AttendanceEvent::query()
            ->where('shift_session_id', $record->shift_session_id)
            ->where('result', 'REVIEW_REQUIRED')
            ->where(fn ($query) => $query->whereNull('reason_code')->orWhere('reason_code', '!=', 'CHECKOUT_LATE'))
            ->exists();
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
            'rules_version' => $session ? (int) ($session->rules_version ?: $session->assignment->rules_version) : null,
            'session' => $session ? WorkTime::local($session->start_at)->format('H:i').'-'.WorkTime::local($session->end_at)->format('H:i') : null,
            'check_in' => $record->check_in_at ? WorkTime::local($record->check_in_at)->format('Y-m-d H:i') : null,
            'check_out' => $record->check_out_at ? WorkTime::local($record->check_out_at)->format('Y-m-d H:i') : null,
            'deadline' => null,
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
