<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'repeat_pattern'])]
class ShiftTemplate extends Model
{
    public function sessions(): HasMany
    {
        return $this->hasMany(ShiftTemplateSession::class)->orderBy('seq');
    }
}
