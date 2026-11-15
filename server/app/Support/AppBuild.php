<?php

namespace App\Support;

class AppBuild
{
    public const RELOGIN_CODE = 'APP_UPDATED_RELOGIN';

    public const RELOGIN_MESSAGE = 'Sistem telah diperbarui. Silakan login ulang untuk memuat data dan fitur terbaru.';

    public const SESSION_KEY = 'auth.app_build_id';

    public static function id(): ?string
    {
        $id = config('app.build_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Aktif hanya bila switch menyala dan build id tersedia; selain itu no-op. */
    public static function forcesRelogin(): bool
    {
        return (bool) config('app.force_relogin_on_update', true) && self::id() !== null;
    }

    public static function isCurrent(?string $buildId): bool
    {
        return ! self::forcesRelogin() || hash_equals((string) self::id(), (string) $buildId);
    }
}
