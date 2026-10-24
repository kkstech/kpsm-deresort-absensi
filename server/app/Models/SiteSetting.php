<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SiteSetting extends Model
{
    protected $fillable = [
        'site_name',
        'contact_email',
        'contact_phone',
        'contact_address',
        'logo_path',
        'favicon_path',
    ];

    protected $appends = ['logo_url', 'favicon_url'];

    public static function current(): self
    {
        return static::query()->findOrFail(1);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getFaviconUrlAttribute(): ?string
    {
        return $this->favicon_path ? Storage::disk('public')->url($this->favicon_path) : null;
    }
}
