<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessRuleException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();
            throw new BusinessRuleException('ACCOUNT_INACTIVE', 'Akun tidak aktif.', 403);
        }

        return $next($request);
    }
}
