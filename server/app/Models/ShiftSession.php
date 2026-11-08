<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['shift_assignment_id', 'seq', 'start_at', 'end_at', 'unpaid_break_minutes', 'policy_snapshot', 'schedule_revision', 'rules_version', 'retired_at'])]
class ShiftSession extends Model
{
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function attendanceRecord(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class, 'shift_session_id');
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'shift_session_id')->orderBy('effective_at')->orderBy('id');
    }

    public function overtimeOrders(): HasMany
    {
        return $this->hasMany(OvertimeOrder::class, 'shift_session_id');
    }

    /** Menit kerja terjadwal: panjang sesi dikurangi istirahat tak dibayar. */
    public function scheduledMinutes(): int
    {
        return max(0, intdiv($this->end_at->getTimestamp() - $this->start_at->getTimestamp(), 60) - $this->unpaid_break_minutes);
    }

    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'unpaid_break_minutes' => 'integer',
            'policy_snapshot' => 'array',
            'schedule_revision' => 'integer',
            'rules_version' => 'integer',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
