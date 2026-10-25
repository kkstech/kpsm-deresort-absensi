<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['year', 'regular_quota', 'day_off_quota', 'opening_confirmed_at', 'opening_confirmed_by'])]
class LeaveYear extends Model
{
    protected function casts(): array
    {
        return ['opening_confirmed_at' => 'immutable_datetime'];
    }
}
