<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $identifier = collect([
                $request->input('username'),
                $request->input('email'),
                $request->input('phone'),
            ])
                ->map(fn ($value) => Str::lower(trim((string) $value)))
                ->first(fn (string $value) => $value !== '') ?? '';

            return [
                Limit::perMinute(5)->by(hash('sha256', $identifier.'|'.$request->ip())),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());
    }
}
