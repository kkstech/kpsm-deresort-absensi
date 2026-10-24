<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['shift_session_id', 'employee_id', 'requester_id', 'direct_by', 'allowed_until', 'allowed_minutes', 'reason', 'status', 'decided_by', 'decided_at', 'decision_reason'])]
class LatePermission extends Model
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
        return ['allowed_until' => 'immutable_datetime', 'allowed_minutes' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
