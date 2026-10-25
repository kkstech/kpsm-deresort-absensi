<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'work_date', 'type', 'status', 'reason', 'decision_reason', 'shift_assignment_id', 'requested_by', 'direct_by', 'decided_by', 'approved_at', 'cancelled_at'])]
class LeaveRequest extends Model
{
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }
}
