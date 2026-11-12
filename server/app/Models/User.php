<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'username', 'phone', 'avatar_path', 'password', 'is_active', 'role'])]
#[Hidden(['password', 'remember_token', 'can_view_all', 'department_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        $path = $this->avatar_path ?? $this->employee?->avatar_path;
        if (! $path) {
            return null;
        }

        return url('/private-media/avatars/users/'.$this->id);
    }

    public const ROLES = ['super_admin', 'admin', 'employee'];

    public const WEB_ROLES = ['super_admin', 'admin'];

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

    public function canApproveTier(string $approvalTier): bool
    {
        return $this->isSuperAdmin() || ($approvalTier === 'admin' && $this->role === 'admin');
    }

    public function canSeeEmployee(Employee $employee): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $employee->user_id === $this->id;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
