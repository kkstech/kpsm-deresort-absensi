<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'work_date',
    'end_date',
    'days_count',
    'type',
    'status',
    'reason',
    'decision_reason',
    'shift_assignment_id',
    'requested_by',
    'direct_by',
    'decided_by',
    'approved_at',
    'cancelled_at',
])]
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

    public function isMultiDay(): bool
    {
        return (int) $this->days_count > 1 || (! empty($this->end_date) && $this->end_date !== $this->work_date);
    }

    public function effectiveEndDate(): string
    {
        return $this->end_date ?: $this->work_date;
    }

    protected function casts(): array
    {
        return [
            'days_count' => 'integer',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
