<?php

namespace App\Models;

use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['shift_session_id', 'employee_id', 'check_in_event_id', 'check_out_event_id', 'late_permission_id', 'overtime_order_id', 'check_in_at', 'check_out_at', 'provisional_out_at', 'checkout_source', 'auto_checkout_at', 'check_in_photo_path', 'check_out_photo_path', 'status', 'needs_review', 'review_reason', 'raw_late_minutes', 'is_late', 'verified_minutes', 'verified_overtime_minutes', 'verified_at', 'verified_by'])]
class AttendanceRecord extends Model
{
    public const CHECKOUT_SOURCE_EMPLOYEE = 'EMPLOYEE';

    public const CHECKOUT_SOURCE_SYSTEM_AUTO = 'SYSTEM_AUTO';

    public const CHECKOUT_SOURCE_HR_CORRECTION = 'HR_CORRECTION';

    protected $appends = ['check_in_photo_url', 'check_out_photo_url'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function latePermission(): BelongsTo
    {
        return $this->belongsTo(LatePermission::class);
    }

    public function overtimeOrder(): BelongsTo
    {
        return $this->belongsTo(OvertimeOrder::class);
    }

    private function resolvePhotoUrl(?string $path, string $kind): ?string
    {
        if (! $path || ! $this->exists) {
            return null;
        }

        return url('/private-media/attendance-records/'.$this->id.'/'.$kind);
    }

    public function getCheckInPhotoUrlAttribute(): ?string
    {
        return $this->resolvePhotoUrl($this->check_in_photo_path, 'check-in');
    }

    public function getCheckOutPhotoUrlAttribute(): ?string
    {
        return $this->resolvePhotoUrl($this->check_out_photo_path, 'check-out');
    }

    public function checkoutSourceLabel(): ?string
    {
        $source = $this->checkout_source ?? ($this->provisional_out_at ? self::CHECKOUT_SOURCE_SYSTEM_AUTO : null);

        return match ($source) {
            self::CHECKOUT_SOURCE_EMPLOYEE => 'Karyawan',
            self::CHECKOUT_SOURCE_SYSTEM_AUTO => 'Checkout otomatis',
            self::CHECKOUT_SOURCE_HR_CORRECTION => 'Koreksi HR',
            default => null,
        };
    }

    /** Menit dibayar = irisan hadir dengan jendela sesi, maksimal panjang sesi dikurangi istirahat. */
    public static function paidMinutes(ShiftSession $session, $in, $out): int
    {
        $checkinEarlyMinutes = (int) ($session->policy_snapshot['checkin_early_minutes'] ?? SiteSetting::checkInEarlyMinutes());
        $from = $checkinEarlyMinutes === 0
            ? $session->start_at->getTimestamp()
            : max($session->start_at->getTimestamp(), $in->getTimestamp());
        $to = min($session->end_at->getTimestamp(), $out->getTimestamp());
        $minutes = min(max(0, intdiv($to - $from, 60)), $session->scheduledMinutes());
        $approvedExits = DB::table('late_permissions')
            ->where('shift_session_id', $session->id)
            ->where('permission_type', 'temporary_exit')
            ->where('status', 'APPROVED')
            ->whereNotNull('leave_at')
            ->whereNotNull('return_at')
            ->get(['leave_at', 'return_at']);

        foreach ($approvedExits as $exit) {
            $exitFrom = max($from, CarbonImmutable::parse($exit->leave_at, 'UTC')->getTimestamp());
            $exitTo = min($to, CarbonImmutable::parse($exit->return_at, 'UTC')->getTimestamp());
            $minutes -= max(0, intdiv($exitTo - $exitFrom, 60));
        }

        return max(0, $minutes);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'shift_session_id' => $this->shift_session_id,
            'late_permission_id' => $this->late_permission_id,
            'late_permission_status' => $this->latePermission?->status,
            'overtime_order_id' => $this->overtime_order_id,
            'status' => $this->status,
            'needs_review' => $this->needs_review,
            'review_reason' => $this->review_reason,
            'check_in_at' => WorkTime::iso($this->check_in_at),
            'check_out_at' => WorkTime::iso($this->check_out_at),
            'provisional_out_at' => WorkTime::iso($this->provisional_out_at),
            'checkout_source' => $this->checkout_source ?? ($this->provisional_out_at ? self::CHECKOUT_SOURCE_SYSTEM_AUTO : null),
            'auto_checkout_at' => WorkTime::iso($this->auto_checkout_at),
            'check_in_photo_url' => $this->check_in_photo_url,
            'check_out_photo_url' => $this->check_out_photo_url,
            'raw_late_minutes' => $this->raw_late_minutes,
            'is_late' => $this->is_late,
            'verified_minutes' => $this->verified_minutes,
            'verified_overtime_minutes' => $this->verified_overtime_minutes,
        ];
    }

    protected function casts(): array
    {
        return [
            'check_in_at' => 'immutable_datetime',
            'check_out_at' => 'immutable_datetime',
            'provisional_out_at' => 'immutable_datetime',
            'auto_checkout_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'needs_review' => 'boolean',
            'is_late' => 'boolean',
            'raw_late_minutes' => 'integer',
            'verified_minutes' => 'integer',
        ];
    }
}
