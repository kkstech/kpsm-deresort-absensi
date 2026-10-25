<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_assignment_id', 'employee_id', 'work_date', 'level_id', 'rate_snapshot', 'meal_amount', 'transport_amount', 'verified_minutes', 'status', 'revision', 'calculated_at'])]
class AllowanceResult extends Model
{
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected function casts(): array
    {
        return ['rate_snapshot' => 'array', 'verified_minutes' => 'integer', 'meal_amount' => 'integer', 'transport_amount' => 'integer', 'revision' => 'integer', 'calculated_at' => 'immutable_datetime'];
    }
}
