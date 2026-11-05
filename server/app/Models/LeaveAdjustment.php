<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'year', 'type', 'delta', 'reason', 'created_by'])]
class LeaveAdjustment extends Model
{
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'type', 'code');
    }
}
