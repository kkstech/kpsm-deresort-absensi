<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'username', 'phone', 'avatar_path', 'password', 'is_active', 'role', 'can_view_all', 'department_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['super_admin', 'admin', 'supervisor', 'employee'];

    public const WEB_ROLES = ['super_admin', 'admin', 'supervisor'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(DeviceRegistration::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    public function seesAllEmployees(): bool
    {
        return $this->isAdmin() || $this->can_view_all;
    }

    public function canSeeEmployee(Employee $employee): bool
    {
        if ($this->seesAllEmployees()) {
            return true;
        }
        if ($this->isSupervisor()) {
            return $this->department_id !== null && $employee->department_id === $this->department_id;
        }

        return $employee->user_id === $this->id;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_view_all' => 'boolean',
        ];
    }
}
