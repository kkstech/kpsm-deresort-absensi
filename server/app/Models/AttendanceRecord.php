<?php

namespace App\Models;

use App\Support\WorkTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_session_id', 'employee_id', 'check_in_event_id', 'check_out_event_id', 'late_permission_id', 'overtime_order_id', 'check_in_at', 'check_out_at', 'provisional_out_at', 'status', 'needs_review', 'review_reason', 'raw_late_minutes', 'is_late', 'verified_minutes', 'verified_overtime_minutes', 'verified_at', 'verified_by'])]
class AttendanceRecord extends Model
{
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

    /** Menit dibayar = irisan hadir dengan jendela sesi, maksimal panjang sesi dikurangi istirahat. */
    public static function paidMinutes(ShiftSession $session, $in, $out): int
    {
        $from = max($session->start_at->getTimestamp(), $in->getTimestamp());
        $to = min($session->end_at->getTimestamp(), $out->getTimestamp());

        return min(max(0, intdiv($to - $from, 60)), $session->scheduledMinutes());
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
            'verified_at' => 'immutable_datetime',
            'needs_review' => 'boolean',
            'is_late' => 'boolean',
            'raw_late_minutes' => 'integer',
            'verified_minutes' => 'integer',
        ];
    }
}
