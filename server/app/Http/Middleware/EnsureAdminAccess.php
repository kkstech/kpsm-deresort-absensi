<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active || ! in_array($user->role, $user::WEB_ROLES, true)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            abort(403, 'Akun ini tidak punya akses ke admin web.');
        }

        return $next($request);
    }
}
