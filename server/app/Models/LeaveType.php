<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'default_quota', 'is_active'])]
class LeaveType extends Model
{
    protected function casts(): array
    {
        return ['default_quota' => 'integer', 'is_active' => 'boolean'];
    }

    public function yearQuotas(): HasMany
    {
        return $this->hasMany(LeaveYearQuota::class);
    }
}
