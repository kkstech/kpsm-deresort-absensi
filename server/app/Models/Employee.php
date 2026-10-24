<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'department_id', 'position_id', 'work_location_id', 'user_id', 'status', 'started_on', 'ended_on', 'phone', 'email'])]
class Employee extends Model
{
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(EmployeePlacement::class)->orderByDesc('effective_from');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(DeviceRegistration::class, 'user_id', 'user_id');
    }
}
