<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessRuleException;
use App\Support\AppBuild;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token aplikasi yang diterbitkan sebelum build server saat ini ditolak,
 * sehingga aplikasi meminta login ulang setelah deploy.
 */
class EnsureCurrentApiBuild
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! AppBuild::forcesRelogin() || ! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if (! AppBuild::isCurrent($token->build_id)) {
            $token->delete();
            throw new BusinessRuleException(AppBuild::RELOGIN_CODE, AppBuild::RELOGIN_MESSAGE, 401);
        }

        return $next($request);
    }
}
