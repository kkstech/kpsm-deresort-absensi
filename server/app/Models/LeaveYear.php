<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['year', 'opening_confirmed_at', 'opening_confirmed_by'])]
class LeaveYear extends Model
{
    public function quotas(): HasMany
    {
        return $this->hasMany(LeaveYearQuota::class);
    }

    public function ensureTypeQuotas(): void
    {
        foreach (LeaveType::query()->get() as $type) {
            $this->quotas()->firstOrCreate(
                ['leave_type_id' => $type->id],
                ['quota' => $type->default_quota],
            );
        }
    }

    protected function casts(): array
    {
        return ['opening_confirmed_at' => 'immutable_datetime'];
    }
}
