<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'requested_by',
    'shift_assignment_id',
    'target_employee_id',
    'target_assignment_id',
    'swap_mode',
    'vacancy_action',
    'reason',
    'status',
    'approval_tier',
    'decided_by',
    'decided_at',
    'decision_reason',
])]
class ShiftExchangeRequest extends Model
{
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function targetEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'target_employee_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function shiftAssignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function targetAssignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'target_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
        ];
    }
}
