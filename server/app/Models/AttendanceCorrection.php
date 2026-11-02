<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['shift_session_id', 'employee_id', 'requester_id', 'requested_check_in_at', 'requested_check_out_at', 'reason', 'status', 'approval_tier', 'decided_by', 'decided_at', 'decision_reason', 'applied_by', 'before_json', 'after_json'])]
class AttendanceCorrection extends Model
{
    public function session(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(AttendanceAttachment::class, 'attachable');
    }

    protected function casts(): array
    {
        return [
            'requested_check_in_at' => 'immutable_datetime',
            'requested_check_out_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'before_json' => 'array',
            'after_json' => 'array',
        ];
    }
}
