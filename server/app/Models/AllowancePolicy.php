<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['level_id', 'effective_from', 'effective_to', 'meal_amount', 'transport_amount', 'created_by'])]
class AllowancePolicy extends Model
{
    public function level(): BelongsTo
    {
        return $this->belongsTo(EmployeeLevel::class, 'level_id');
    }
}
