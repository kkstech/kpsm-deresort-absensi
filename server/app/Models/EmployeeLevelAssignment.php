<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'level_id', 'effective_from', 'effective_to', 'reason', 'created_by'])]
class EmployeeLevelAssignment extends Model
{
    public function level(): BelongsTo
    {
        return $this->belongsTo(EmployeeLevel::class, 'level_id');
    }
}
