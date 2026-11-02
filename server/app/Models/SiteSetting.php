<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $fillable = [
        'site_name',
        'contact_email',
        'contact_phone',
        'contact_address',
        'logo_path',
        'favicon_path',
        'checkin_early_minutes',
        'retirement_age_years',
    ];

    protected $appends = ['logo_url', 'favicon_url'];

    public static function current(): self
    {
        return static::query()->findOrFail(1);
    }

    public static function checkInEarlyMinutes(): int
    {
        return Cache::rememberForever('attendance.checkin_early_minutes', fn (): int => (int) (static::current()->checkin_early_minutes ?? config('attendance.checkin_early_minutes')));
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/storage/'.ltrim($this->logo_path, '/') : null;
    }

    public function getFaviconUrlAttribute(): ?string
    {
        return $this->favicon_path ? '/storage/'.ltrim($this->favicon_path, '/') : null;
    }

    protected function casts(): array
    {
        return [
            'checkin_early_minutes' => 'integer',
            'retirement_age_years' => 'integer',
        ];
    }
}
