<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceWorkflowController;
use App\Http\Controllers\AttendanceAttachmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active', 'device'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('me/schedule', [AttendanceController::class, 'schedule']);
        Route::post('attendance/events', [AttendanceController::class, 'storeEvent']);
        Route::post('attendance/sync', [AttendanceController::class, 'sync']);
        Route::get('attendance/me', [AttendanceController::class, 'history']);
        Route::get('late-permissions', [AttendanceWorkflowController::class, 'permissions']);
        Route::post('late-permissions', [AttendanceWorkflowController::class, 'storePermission']);
        Route::post('late-permissions/{permission}/cancel', [AttendanceWorkflowController::class, 'cancelPermission'])->whereNumber('permission');
        Route::get('attendance-corrections', [AttendanceWorkflowController::class, 'corrections']);
        Route::post('attendance-corrections', [AttendanceWorkflowController::class, 'storeCorrection']);
        Route::post('attendance-corrections/{correction}/cancel', [AttendanceWorkflowController::class, 'cancelCorrection'])->whereNumber('correction');
        Route::get('attendance/attachments/{attachment}', [AttendanceAttachmentController::class, 'mobile'])->whereNumber('attachment');
    });
});
