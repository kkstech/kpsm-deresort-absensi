<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceEvent;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LatePermission;
use App\Models\ShiftSession;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Satu event check-in/out milik karyawan yang login.
 * Event mentah selalu disimpan (termasuk yang ditolak) supaya bisa diaudit.
 */
class RecordAttendance
{
    /** @return array{http:int, data:array} */
    public function handle(Employee $employee, array $input, ?int $deviceId = null, ?int $batchId = null): array
    {
        $hash = $this->hash($input);
        if ($existing = $this->duplicate($input['event_uuid'], $hash, $employee)) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->record($employee, $input, $hash, $deviceId, $batchId));
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate($input['event_uuid'], $hash, $employee)
                ?? throw new BusinessRuleException('STATE_CONFLICT', 'Event sedang diproses. Coba lagi.');
        }
    }

    private function record(Employee $employee, array $input, string $hash, ?int $deviceId, ?int $batchId): array
    {
        Employee::query()->lockForUpdate()->findOrFail($employee->id);
        $session = ShiftSession::query()->with('assignment')->lockForUpdate()->find($input['shift_session_id']);
        if (! $session || $session->assignment->employee_id !== $employee->id) {
            throw new BusinessRuleException('FORBIDDEN', 'Sesi ini bukan milik Anda.', 403);
        }

        $now = WorkTime::now();
        $offline = (bool) ($input['captured_offline'] ?? false);
        $deviceAt = isset($input['device_at']) ? CarbonImmutable::parse($input['device_at'])->utc() : null;
        if ($offline && ! $deviceAt) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Event offline wajib membawa device_at.', 422, ['device_at' => ['Wajib untuk event offline.']]);
        }
        $effective = $offline ? $deviceAt : $now;
        $record = AttendanceRecord::query()->where('shift_session_id', $session->id)->lockForUpdate()->first();
        [$distance, $locationProblem] = $this->checkLocation($session, $input['location'] ?? null);

        $problem = $this->scheduleProblem($session, $input['event_type'], $effective, $record)
            ?? $locationProblem
            ?? ($offline ? $this->offlineClockProblem($deviceAt, $now) : null);
        if ($problem === null && $input['event_type'] === 'CHECK_IN' && $record?->check_in_at === null
            && (int) $session->assignment->rules_version >= 2) {
            $problem = app(AutoCheckoutAttendance::class)->closeExpiredBeforeNextCheckin($employee, $session->id, $now);
        }
        $result = match (true) {
            $problem && ! $offline && ! str_starts_with($problem, 'GPS_ACCURACY') => 'REJECTED',
            $problem !== null, $offline => 'REVIEW_REQUIRED',
            default => 'ACCEPTED',
        };
        $reason = $problem ?? ($offline ? 'OFFLINE_CLOCK_UNVERIFIED' : null);

        $keepLocation = $session->policy_snapshot['use_location_check'] ?? false;
        $event = AttendanceEvent::query()->create([
            'event_uuid' => $input['event_uuid'], 'payload_hash' => $hash, 'employee_id' => $employee->id,
            'shift_session_id' => $session->id, 'device_registration_id' => $deviceId, 'sync_batch_id' => $batchId,
            'type' => $input['event_type'], 'source' => $offline ? 'OFFLINE' : 'ONLINE',
            'server_received_at' => $now, 'device_time' => $deviceAt, 'device_timezone' => $input['device_timezone'] ?? null,
            'effective_at' => $effective,
            'latitude' => $keepLocation ? ($input['location']['latitude'] ?? null) : null,
            'longitude' => $keepLocation ? ($input['location']['longitude'] ?? null) : null,
            'accuracy_meters' => $keepLocation && isset($input['location']['accuracy_meters']) ? (int) round($input['location']['accuracy_meters']) : null,
            'distance_meters' => $distance, 'local_seq' => $input['local_sequence'] ?? null,
            'result' => $result, 'reason_code' => $reason, 'created_at' => $now,
        ]);
        Audit::log('attendance.event_recorded', $event);

        $nonProjectable = [
            'SCHEDULE_CANCELLED', 'SCHEDULE_NOT_PUBLISHED', 'PERIOD_LOCKED', 'ALREADY_CHECKED_IN',
            'ALREADY_CHECKED_OUT', 'NOT_CHECKED_IN', 'OUTSIDE_WINDOW', 'OFFLINE_CLOCK_FUTURE', 'OFFLINE_TOO_OLD',
            'SCHEDULE_CHANGED', 'OPEN_SESSION_EXISTS', 'PREVIOUS_PERIOD_LOCKED', 'AUTO_CHECKOUT_DUE',
        ];
        if ($result !== 'REJECTED' && ! in_array($problem, $nonProjectable, true)) {
            $record = $this->project($session, $event, $record, $result === 'REVIEW_REQUIRED');
            if ($event->type === 'CHECK_IN') {
                $record = $this->reconcilePendingCheckout($session, $record);
            }
            app(CalculateCycleAllowance::class)->refresh($session->assignment);
        }

        return ['http' => 201, 'data' => $this->response($event, $record)];
    }

    private function scheduleProblem(ShiftSession $session, string $type, CarbonImmutable $at, ?AttendanceRecord $record): ?string
    {
        $assignment = $session->assignment;
        $early = config('attendance.checkin_early_minutes');
        $late = config('attendance.checkout_late_minutes');
        $checkoutDeadline = $session->end_at->addMinutes($late);
        if ((int) $assignment->rules_version >= 2) {
            if ($assignment->template_type === 'regular') {
                $checkoutDeadline = $record?->check_in_at?->addMinutes(13 * 60);
            } else {
                $firstCheckIn = $assignment->sessions()->with('attendanceRecord')->get()
                    ->map(fn (ShiftSession $candidate) => $candidate->attendanceRecord?->check_in_at)
                    ->filter()->sortBy(fn ($checkIn) => $checkIn->getTimestamp())->first();
                $cycleDeadline = $firstCheckIn?->addMinutes(13 * 60);
                $checkoutDeadline = $cycleDeadline && $cycleDeadline->lt($session->end_at->addMinutes(60))
                    ? $cycleDeadline : $session->end_at->addMinutes(60);
            }
        }

        return match (true) {
            $session->retired_at !== null => 'SCHEDULE_CHANGED',
            $assignment->status === 'CANCELLED' => 'SCHEDULE_CANCELLED',
            $assignment->status === 'DRAFT' => 'SCHEDULE_NOT_PUBLISHED',
            $this->periodLocked($assignment->work_date) => 'PERIOD_LOCKED',
            $type === 'CHECK_IN' && $record?->check_in_at !== null => 'ALREADY_CHECKED_IN',
            $type === 'CHECK_IN' && ($at->lt($session->start_at->subMinutes($early)) || $at->gte($session->end_at)) => 'OUTSIDE_WINDOW',
            $type === 'CHECK_OUT' && $record?->check_in_at === null => 'NOT_CHECKED_IN',
            $type === 'CHECK_OUT' && $record?->check_out_at !== null => 'ALREADY_CHECKED_OUT',
            $type === 'CHECK_OUT' && ($at->lte($record->check_in_at) || ($checkoutDeadline && $at->gt($checkoutDeadline))) => 'OUTSIDE_WINDOW',
            $type === 'CHECK_OUT' && (int) $assignment->rules_version >= 2 && $assignment->template_type === 'regular'
                && $at->gte($record->check_in_at->addMinutes(13 * 60)) => 'AUTO_CHECKOUT_DUE',
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

    private function offlineClockProblem(CarbonImmutable $deviceAt, CarbonImmutable $now): ?string
    {
        return match (true) {
            $deviceAt->gt($now->addMinutes(config('attendance.offline_future_tolerance_minutes'))) => 'OFFLINE_CLOCK_FUTURE',
            $deviceAt->lt($now->subHours(config('attendance.offline_max_age_hours'))) => 'OFFLINE_TOO_OLD',
            default => null,
        };
    }

    /** @return array{0:?int,1:?string} jarak meter dan kode masalah lokasi */
    private function checkLocation(ShiftSession $session, ?array $location): array
    {
        $place = $session->policy_snapshot['location'] ?? null;
        if (! ($session->policy_snapshot['use_location_check'] ?? false)) {
            return [null, null];
        }
        if (! $location || ($place['mode'] ?? null) !== 'radius') {
            return [null, 'GPS_REQUIRED'];
        }

        $distance = (int) round($this->haversine((float) $place['latitude'], (float) $place['longitude'], (float) $location['latitude'], (float) $location['longitude']));
        $accuracy = $location['accuracy_meters'] ?? null;

        return [$distance, match (true) {
            $distance > $place['radius_meters'] => 'OUTSIDE_RADIUS',
            $place['max_accuracy_meters'] && ($accuracy === null || $accuracy > $place['max_accuracy_meters']) => 'GPS_ACCURACY_LOW',
            default => null,
        }];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private function project(ShiftSession $session, AttendanceEvent $event, ?AttendanceRecord $record, bool $review): AttendanceRecord
    {
        $record ??= new AttendanceRecord(['shift_session_id' => $session->id, 'employee_id' => $event->employee_id]);
        $needsReview = $record->needs_review || $review;

        if ($event->type === 'CHECK_IN') {
            $late = WorkTime::minutesBetween($session->start_at, $event->effective_at);
            $permission = LatePermission::query()->where('shift_session_id', $session->id)->where('status', 'APPROVED')
                ->orderByDesc('id')->get()->first(fn (LatePermission $candidate) => $this->permissionCovers($session, $candidate, $event->effective_at));
            $record->fill([
                'check_in_event_id' => $event->id, 'check_in_at' => $event->effective_at, 'status' => 'IN_PROGRESS',
                'raw_late_minutes' => $late, 'is_late' => $late > ($session->policy_snapshot['grace_minutes'] ?? 0),
                'late_permission_id' => $permission?->id,
            ]);
        } else {
            $afterAuto = $record->provisional_out_at !== null;
            $needsReview = $needsReview || $afterAuto;
            $record->fill([
                'check_out_event_id' => $event->id, 'check_out_at' => $event->effective_at,
                'status' => $afterAuto ? 'PROVISIONAL' : 'COMPLETE',
                'verified_minutes' => $needsReview ? 0 : AttendanceRecord::paidMinutes($session, $record->check_in_at, $event->effective_at),
            ]);
            if ($afterAuto) {
                $record->review_reason = 'CHECKOUT_AFTER_AUTO';
            }
        }
        if ($review && ! $record->review_reason) {
            $record->review_reason = $event->reason_code;
        }
        $record->needs_review = $needsReview;
        $record->save();
        $this->syncAssignmentStatus($session);

        return $record;
    }

    private function permissionCovers(ShiftSession $session, LatePermission $permission, CarbonImmutable $checkIn): bool
    {
        return $permission->allowed_until
            ? $checkIn->lte($permission->allowed_until)
            : WorkTime::minutesBetween($session->start_at, $checkIn) <= $permission->allowed_minutes;
    }

    private function reconcilePendingCheckout(ShiftSession $session, AttendanceRecord $record): AttendanceRecord
    {
        if ($record->check_out_at || $record->provisional_out_at) {
            return $record;
        }

        $now = WorkTime::now();
        $event = AttendanceEvent::query()
            ->where('shift_session_id', $session->id)
            ->where('type', 'CHECK_OUT')
            ->where('source', 'OFFLINE')
            ->where('result', 'REVIEW_REQUIRED')
            ->where('reason_code', 'NOT_CHECKED_IN')
            ->where('effective_at', '>', $record->check_in_at)
            ->where('effective_at', '<=', $session->end_at->addMinutes(config('attendance.checkout_late_minutes')))
            ->whereBetween('effective_at', [
                $now->subHours(config('attendance.offline_max_age_hours')),
                $now->addMinutes(config('attendance.offline_future_tolerance_minutes')),
            ])
            ->orderBy('effective_at')
            ->orderBy('id')
            ->first();

        if (! $event) {
            return $record;
        }

        return $this->project($session, $event, $record, true);
    }

    private function syncAssignmentStatus(ShiftSession $session): void
    {
        $session->assignment->syncAttendanceStatus();
    }

    private function duplicate(string $uuid, string $hash, Employee $employee): ?array
    {
        $event = AttendanceEvent::query()->where('event_uuid', $uuid)->first();
        if (! $event) {
            return null;
        }
        if ($event->payload_hash !== $hash || $event->employee_id !== $employee->id) {
            throw new BusinessRuleException('UUID_PAYLOAD_CONFLICT', 'event_uuid sudah dipakai untuk data lain.');
        }
        $record = AttendanceRecord::query()->where('shift_session_id', $event->shift_session_id)->first();

        return ['http' => 200, 'data' => [...$this->response($event, $record), 'status' => 'DUPLICATE', 'original_status' => $event->result]];
    }

    private function response(AttendanceEvent $event, ?AttendanceRecord $record): array
    {
        return [
            'event_uuid' => $event->event_uuid,
            'status' => $event->result,
            'reason_code' => $event->reason_code,
            'effective_at' => WorkTime::iso($event->effective_at),
            'server_received_at' => WorkTime::iso($event->server_received_at),
            'attendance_record_status' => $record?->status ?? 'NOT_STARTED',
            'record' => $record?->toApi(),
        ];
    }

    private function hash(array $input): string
    {
        $fields = ['event_uuid', 'shift_session_id', 'event_type', 'device_id', 'device_at', 'device_timezone', 'captured_offline', 'local_sequence', 'location'];
        $canonical = array_map(fn ($k) => $input[$k] ?? null, array_combine($fields, $fields));
        $canonical['captured_offline'] = (bool) $canonical['captured_offline'];
        $canonical['shift_session_id'] = (int) $canonical['shift_session_id'];

        return hash('sha256', json_encode($canonical));
    }
}
