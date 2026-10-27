<?php

use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\LeaveController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceWorkflowController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ShiftExchangeController;
use App\Http\Controllers\AttendanceAttachmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active', 'device'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'updateProfile']);
        Route::post('me/avatar', [AuthController::class, 'uploadAvatar']);
        Route::delete('me/avatar', [AuthController::class, 'deleteAvatar']);
        Route::get('me/schedule', [AttendanceController::class, 'schedule']);
        Route::get('me/leave-balance', [LeaveController::class, 'balance']);
        Route::get('leaves', [LeaveController::class, 'employeeIndex']);
        Route::post('leaves', [LeaveController::class, 'store']);
        Route::post('leaves/{leave}/cancel', [LeaveController::class, 'cancelEmployee'])->whereNumber('leave');
        Route::post('attendance/events', [AttendanceController::class, 'storeEvent']);
        Route::get('attendance/events/{uuid}', [AttendanceController::class, 'eventReceipt'])->whereUuid('uuid');
        Route::post('attendance/sync', [AttendanceController::class, 'sync']);
        Route::get('attendance/me', [AttendanceController::class, 'history']);
        Route::get('late-permissions', [AttendanceWorkflowController::class, 'permissions']);
        Route::post('late-permissions', [AttendanceWorkflowController::class, 'storePermission']);
        Route::post('late-permissions/{permission}/cancel', [AttendanceWorkflowController::class, 'cancelPermission'])->whereNumber('permission');
        Route::get('attendance-corrections', [AttendanceWorkflowController::class, 'corrections']);
        Route::post('attendance-corrections', [AttendanceWorkflowController::class, 'storeCorrection']);
        Route::post('attendance-corrections/{correction}/cancel', [AttendanceWorkflowController::class, 'cancelCorrection'])->whereNumber('correction');
        Route::get('attendance/attachments/{attachment}', [AttendanceAttachmentController::class, 'mobile'])->whereNumber('attachment');

        // Shift Exchange (Pengajuan & Pengaturan Tukar Shift)
        Route::get('shift-exchanges/colleagues', [ShiftExchangeController::class, 'colleagues']);
        Route::get('shift-exchanges/employee-schedules', [ShiftExchangeController::class, 'employeeSchedules']);
        Route::get('shift-exchanges', [ShiftExchangeController::class, 'index']);
        Route::post('shift-exchanges', [ShiftExchangeController::class, 'store']);
        Route::post('shift-exchanges/{shiftExchange}/cancel', [ShiftExchangeController::class, 'cancel'])->whereNumber('shiftExchange');
        Route::post('shift-exchanges/{shiftExchange}/decide', [ShiftExchangeController::class, 'decide'])->whereNumber('shiftExchange');

        // Admin/HRD Schedule Management & Swap Execution
        Route::get('admin/schedule', [ScheduleController::class, 'index']);
        Route::post('admin/schedule/swap/preview', [ScheduleController::class, 'previewSwap']);
        Route::post('admin/schedule/swap', [ScheduleController::class, 'swap']);
        Route::post('admin/schedule/copy-week', [ScheduleController::class, 'copyWeek']);
        Route::post('admin/schedule/copy-month', [ScheduleController::class, 'copyMonth']);
        Route::get('admin/employees', [EmployeeController::class, 'index']);
    });
});
