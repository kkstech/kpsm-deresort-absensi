<?php

namespace App\Http\Middleware;

use App\Support\AppBuild;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi admin web yang dibuat sebelum build server saat ini dikeluarkan,
 * supaya data dan fitur baru termuat setelah deploy.
 */
class EnsureCurrentWebBuild
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AppBuild::forcesRelogin() || ! Auth::guard('web')->check()) {
            return $next($request);
        }

        $sessionBuild = $request->session()->get(AppBuild::SESSION_KEY);
        if ($sessionBuild === null && Auth::guard('web')->viaRemember()) {
            // Login via cookie "ingat saya": sah selama login terakhir terjadi di build ini.
            $sessionBuild = Auth::guard('web')->user()?->web_login_build_id;
            if (AppBuild::isCurrent($sessionBuild)) {
                $request->session()->put(AppBuild::SESSION_KEY, AppBuild::id());
            }
        }

        if (AppBuild::isCurrent($sessionBuild)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', AppBuild::RELOGIN_MESSAGE);

        if ($request->is('admin/api/*') || $request->expectsJson()) {
            return response()->json([
                'code' => AppBuild::RELOGIN_CODE,
                'message' => AppBuild::RELOGIN_MESSAGE,
                'request_id' => $request->attributes->get('request_id'),
            ], 401);
        }

        return redirect()->route('login');
    }
}
