<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessRuleException;
use App\Models\DeviceRegistration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegisteredDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || ! $token || ! isset($token->name) || ! preg_match('/^device:(\d+)$/', $token->name, $match)) {
            throw new BusinessRuleException('UNREGISTERED_DEVICE', 'Perangkat belum terdaftar.', 401);
        }

        $device = DeviceRegistration::query()
            ->whereKey($match[1])
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first();
        if (! $device) {
            $token->delete();
            throw new BusinessRuleException('UNREGISTERED_DEVICE', 'Perangkat sudah dicabut atau tidak terdaftar.', 401);
        }

        $request->attributes->set('device_registration', $device);

        return $next($request);
    }
}
