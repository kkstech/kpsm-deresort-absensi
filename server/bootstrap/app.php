<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureRegisteredDevice;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$isJson = fn (Request $request) => $request->is('api/*') || $request->is('admin/api/*') || $request->expectsJson();

$error = function (Request $request, int $status, string $code, string $message, array $details = []) {
    return response()->json(array_filter([
        'code' => $code,
        'message' => $message,
        'details' => $details ?: null,
        'request_id' => $request->attributes->get('request_id'),
    ], fn ($value) => $value !== null), $status);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES'));
        $middleware->alias([
            'admin' => EnsureAdminAccess::class,
            'active' => EnsureUserIsActive::class,
            'device' => EnsureRegisteredDevice::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isJson, $error): void {
        $exceptions->shouldRenderJsonWhen($isJson);

        $exceptions->render(function (Throwable $e, Request $request) use ($isJson, $error) {
            if (! $isJson($request)) {
                return null;
            }

            return match (true) {
                $e instanceof BusinessRuleException => $error($request, $e->status, $e->errorCode, $e->getMessage(), $e->details),
                $e instanceof ValidationException => $error($request, 422, 'VALIDATION_ERROR', 'Data belum valid.', $e->errors()),
                $e instanceof AuthenticationException => $error($request, 401, 'UNAUTHENTICATED', 'Silakan login.'),
                $e instanceof AuthorizationException => $error($request, 403, 'FORBIDDEN', 'Tidak punya akses.'),
                $e instanceof ThrottleRequestsException => $error($request, 429, 'RATE_LIMITED', 'Terlalu banyak percobaan. Coba lagi sebentar.'),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $error($request, 404, 'NOT_FOUND', 'Data tidak ditemukan.'),
                $e instanceof QueryException && str_contains(strtolower($e->getMessage()), 'foreign key') => $error($request, 409, 'IN_USE', 'Data masih dipakai data lain.'),
                $e instanceof QueryException && str_contains(strtolower($e->getMessage()), 'unique') => $error($request, 409, 'DUPLICATE', 'Data sudah ada.'),
                $e instanceof HttpExceptionInterface => $error($request, $e->getStatusCode(), $e->getStatusCode() === 403 ? 'FORBIDDEN' : 'HTTP_ERROR', $e->getMessage() ?: 'Permintaan ditolak.'),
                default => $error($request, 500, 'SERVER_ERROR', 'Terjadi kesalahan di server.'),
            };
        });
    })->create();
