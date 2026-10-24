<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_template_id', 'seq', 'local_start', 'local_end', 'day_offset', 'unpaid_break_minutes'])]
class ShiftTemplateSession extends Model
{
    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }
}
