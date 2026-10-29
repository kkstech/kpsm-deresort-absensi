<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['code', 'name', 'department_id', 'position_id', 'level_id', 'work_location_id', 'user_id', 'status', 'started_on', 'ended_on', 'phone', 'email', 'avatar_path'])]
class Employee extends Model
{
    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        if (app()->environment('testing')) {
            return Storage::disk('public')->exists($this->avatar_path)
                ? url('storage/'.ltrim($this->avatar_path, '/'))
                : null;
        }

        $relPath = ltrim($this->avatar_path, '/');
        if (! is_file(public_path('storage/'.$relPath)) && ! is_file(storage_path('app/public/'.$relPath))) {
            return null;
        }

        return url('storage/'.$relPath);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(EmployeeLevel::class, 'level_id');
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

    public function employmentTerms(): HasMany
    {
        return $this->hasMany(EmploymentTerm::class)->orderBy('starts_on');
    }

    public function levelAssignments(): HasMany
    {
        return $this->hasMany(EmployeeLevelAssignment::class)->orderBy('effective_from');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(DeviceRegistration::class, 'user_id', 'user_id');
    }
}
