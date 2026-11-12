<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.reauthenticated_at', 0);
        $confirmedUser = (int) $request->session()->get('auth.reauthenticated_user_id', 0);
        $isRecent = $confirmedAt >= now()->subMinutes(5)->timestamp;

        if (! $isRecent || $confirmedUser !== (int) $request->user()->id) {
            return new JsonResponse([
                'code' => 'REAUTHENTICATION_REQUIRED',
                'message' => 'Konfirmasi kata sandi Super Admin sebelum melanjutkan.',
            ], 403);
        }

        return $next($request);
    }
}
