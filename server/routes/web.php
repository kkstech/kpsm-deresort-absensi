<?php

use App\Http\Controllers\Admin\AttendanceMonitoringController;
use App\Http\Controllers\Admin\AttendanceWorkflowController;
use App\Http\Controllers\AttendanceAttachmentController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SiteSettingController;
use App\Http\Controllers\Admin\TemplateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::view('/admin', 'admin.dashboard')->name('admin.dashboard');

    Route::prefix('admin/api')->group(function () {
        Route::get('attendance/filters', [AttendanceMonitoringController::class, 'filters']);
        Route::get('dashboard/attendance', [AttendanceMonitoringController::class, 'dashboard']);
        Route::get('attendance', [AttendanceMonitoringController::class, 'index']);
        Route::get('workflows', [AttendanceWorkflowController::class, 'index']);
        Route::post('late-permissions/direct', [AttendanceWorkflowController::class, 'directLatePermission']);
        Route::post('late-permissions/{permission}/decision', [AttendanceWorkflowController::class, 'decideLatePermission'])->whereNumber('permission');
        Route::post('attendance-corrections/direct/{session}', [AttendanceWorkflowController::class, 'directCorrection'])->whereNumber('session');
        Route::post('attendance-corrections/{correction}/decision', [AttendanceWorkflowController::class, 'decideCorrection'])->whereNumber('correction');
        Route::post('attendance/{session}/verify', [AttendanceWorkflowController::class, 'verify'])->whereNumber('session');
        Route::get('attachments/{attachment}', [AttendanceAttachmentController::class, 'admin'])->whereNumber('attachment');
        Route::get('site-settings', [SiteSettingController::class, 'show']);
        Route::post('site-settings', [SiteSettingController::class, 'update']);

        Route::get('{catalog}', [CatalogController::class, 'index'])->whereIn('catalog', ['departments', 'positions']);
        Route::post('{catalog}', [CatalogController::class, 'store'])->whereIn('catalog', ['departments', 'positions']);
        Route::put('{catalog}/{id}', [CatalogController::class, 'update'])->whereIn('catalog', ['departments', 'positions'])->whereNumber('id');
        Route::delete('{catalog}/{id}', [CatalogController::class, 'destroy'])->whereIn('catalog', ['departments', 'positions'])->whereNumber('id');

        Route::apiResource('locations', LocationController::class)->except('show');
        Route::apiResource('templates', TemplateController::class)->except('show');
        Route::apiResource('policies', PolicyController::class)->except('show');

        Route::apiResource('employees', EmployeeController::class)->except('destroy');
        Route::post('employees/{employee}/account', [EmployeeController::class, 'account']);
        Route::post('employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
        Route::post('employees/{employee}/revoke-devices', [EmployeeController::class, 'revokeDevices']);

        Route::get('schedule', [ScheduleController::class, 'index']);
        Route::post('schedule', [ScheduleController::class, 'store']);
        Route::delete('schedule/{assignment}', [ScheduleController::class, 'destroy']);
        Route::post('schedule/publish', [ScheduleController::class, 'publish']);
        Route::post('schedule/copy-week', [ScheduleController::class, 'copyWeek']);

        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
    });
});
