<?php

use App\Http\Controllers\Admin\AllowanceController;
use App\Http\Controllers\Admin\AttendanceMonitoringController;
use App\Http\Controllers\Admin\AttendanceWorkflowController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DatabaseBackupController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeLevelController;
use App\Http\Controllers\Admin\LeaveController;
use App\Http\Controllers\Admin\LeaveReasonController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\OvertimeAdminController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ShiftCategoryController;
use App\Http\Controllers\Admin\SiteSettingController;
use App\Http\Controllers\Admin\TemplateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ShiftExchangeController;
use App\Http\Controllers\AttendanceAttachmentController;
use App\Http\Controllers\PrivateMediaController;
use App\Http\Controllers\Auth\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [WebAuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
});

Route::middleware(['auth:sanctum', 'active', 'private-media'])->prefix('private-media')->group(function () {
    Route::get('avatars/employees/{employee}', [PrivateMediaController::class, 'employeeAvatar'])->whereNumber('employee');
    Route::get('avatars/users/{user}', [PrivateMediaController::class, 'userAvatar'])->whereNumber('user');
    Route::get('attendance-records/{record}/{photo}', [PrivateMediaController::class, 'attendanceRecordPhoto'])
        ->whereNumber('record')->whereIn('photo', ['check-in', 'check-out']);
    Route::get('attendance-events/{event}/photo', [PrivateMediaController::class, 'attendanceEventPhoto'])->whereNumber('event');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::view('/admin', 'admin.dashboard')->name('admin.dashboard');

    Route::prefix('admin/api')->group(function () {
        Route::get('attendance/filters', [AttendanceMonitoringController::class, 'filters']);
        Route::get('dashboard/attendance', [AttendanceMonitoringController::class, 'dashboard']);
        Route::get('attendance', [AttendanceMonitoringController::class, 'index']);
        Route::get('attendance/{session}/events', [AttendanceMonitoringController::class, 'events'])->whereNumber('session');
        Route::get('reports/filters', [ReportController::class, 'filters']);
        Route::get('reports/summary', [ReportController::class, 'summary']);
        Route::get('reports/details', [ReportController::class, 'details']);
        Route::get('reports/shift-breakdown', [ReportController::class, 'shiftBreakdown']);
        Route::get('reports/overtime', [ReportController::class, 'overtime']);
        Route::post('reports/finalize', [ReportController::class, 'finalize']);
        Route::post('reports/reopen', [ReportController::class, 'reopen']);
        Route::get('reports/export/excel', [ReportController::class, 'exportExcel']);
        Route::get('reports/export/pdf', [ReportController::class, 'exportPdf']);
        Route::get('workflows', [AttendanceWorkflowController::class, 'index']);
        Route::get('workflows/history', [AttendanceWorkflowController::class, 'history']);
        Route::get('shift-exchanges', [ShiftExchangeController::class, 'index']);
        Route::post('shift-exchanges/{shiftExchange}/decide', [ShiftExchangeController::class, 'decide'])->whereNumber('shiftExchange');
        Route::get('overtimes', [OvertimeAdminController::class, 'index']);
        Route::get('overtimes/recommendations', [OvertimeAdminController::class, 'recommendations']);
        Route::post('overtimes/order', [OvertimeAdminController::class, 'order']);
        Route::post('overtimes/{order}/decision', [OvertimeAdminController::class, 'decision'])->whereNumber('order');
        Route::post('overtimes/{order}/cancel', [OvertimeAdminController::class, 'cancel'])->whereNumber('order');
        Route::get('notifications', [NotificationController::class, 'adminIndex']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
        Route::post('late-permissions/direct', [AttendanceWorkflowController::class, 'directLatePermission']);
        Route::post('late-permissions/{permission}/decision', [AttendanceWorkflowController::class, 'decideLatePermission'])->whereNumber('permission');
        Route::post('attendance-corrections/direct/{session}', [AttendanceWorkflowController::class, 'directCorrection'])->whereNumber('session');
        Route::post('attendance-corrections/{correction}/decision', [AttendanceWorkflowController::class, 'decideCorrection'])->whereNumber('correction');
        Route::post('attendance/{session}/verify', [AttendanceWorkflowController::class, 'verify'])->whereNumber('session');
        Route::get('attachments/{attachment}', [AttendanceAttachmentController::class, 'admin'])->whereNumber('attachment');
        Route::get('attachments/{attachment}/preview', [AttendanceAttachmentController::class, 'preview'])->whereNumber('attachment');
        Route::get('site-settings', [SiteSettingController::class, 'show']);
        Route::post('site-settings', [SiteSettingController::class, 'update']);
        Route::get('attendance-settings', [SiteSettingController::class, 'attendance']);
        Route::put('attendance-settings', [SiteSettingController::class, 'updateAttendance']);
        Route::get('database-backup/status', [DatabaseBackupController::class, 'status']);
        Route::post('database-backup/confirm', [DatabaseBackupController::class, 'confirm'])->middleware('throttle:5,1');
        Route::get('database-backup', [DatabaseBackupController::class, 'download'])->middleware('recent-password');
        Route::get('database-backup/safety', [DatabaseBackupController::class, 'downloadSafetyBackup'])->middleware('recent-password');
        Route::post('database-backup/restore', [DatabaseBackupController::class, 'restore'])->middleware(['recent-password', 'throttle:3,1']);
        Route::patch('me', [AuthController::class, 'updateProfile']);
        Route::post('me/avatar', [AuthController::class, 'uploadAvatar']);
        Route::delete('me/avatar', [AuthController::class, 'deleteAvatar']);

        Route::get('{catalog}', [CatalogController::class, 'index'])->whereIn('catalog', ['departments', 'positions']);
        Route::post('{catalog}', [CatalogController::class, 'store'])->whereIn('catalog', ['departments', 'positions']);
        Route::put('{catalog}/{id}', [CatalogController::class, 'update'])->whereIn('catalog', ['departments', 'positions'])->whereNumber('id');
        Route::delete('{catalog}/{id}', [CatalogController::class, 'destroy'])->whereIn('catalog', ['departments', 'positions'])->whereNumber('id');

        Route::apiResource('locations', LocationController::class)->except('show');
        Route::apiResource('templates', TemplateController::class)->except('show');
        Route::get('shift-categories', [ShiftCategoryController::class, 'index']);
        Route::post('shift-categories', [ShiftCategoryController::class, 'store']);
        Route::put('shift-categories/{shiftCategory}', [ShiftCategoryController::class, 'update'])->whereNumber('shiftCategory');
        Route::apiResource('policies', PolicyController::class)->except('show');

        Route::get('unlinked-users', [EmployeeController::class, 'unlinkedUsers']);
        Route::get('unlinked-employees', [EmployeeController::class, 'unlinkedEmployees']);
        Route::get('employees/import-template', [EmployeeController::class, 'downloadImportTemplate']);
        Route::post('employees/import-preview', [EmployeeController::class, 'previewImport']);
        Route::post('employees/import', [EmployeeController::class, 'importEmployees']);

        Route::apiResource('employees', EmployeeController::class)->except('destroy');
        Route::post('employees/{employee}/account', [EmployeeController::class, 'account']);
        Route::post('employees/{employee}/link-user', [EmployeeController::class, 'linkUser']);
        Route::post('employees/{employee}/unlink-user', [EmployeeController::class, 'unlinkUser']);
        Route::post('employees/{employee}/deactivate', [EmployeeController::class, 'deactivate']);
        Route::get('employees/{employee}/devices', [EmployeeController::class, 'devices']);
        Route::post('employees/{employee}/revoke-devices', [EmployeeController::class, 'revokeDevices']);
        Route::post('employees/{employee}/employment', [EmployeeController::class, 'setEmployment']);
        Route::get('employees/{employee}/levels', [EmployeeLevelController::class, 'history']);
        Route::post('employee-levels/{employee}/assign', [EmployeeLevelController::class, 'assign']);
        Route::get('employment-alerts', [EmployeeController::class, 'employmentAlerts']);

        Route::get('employee-levels', [EmployeeLevelController::class, 'index']);
        Route::post('employee-levels', [EmployeeLevelController::class, 'store']);
        Route::put('employee-levels/{level}', [EmployeeLevelController::class, 'update']);
        Route::delete('employee-levels/{level}', [EmployeeLevelController::class, 'destroy']);

        Route::get('levels', [EmployeeLevelController::class, 'index']);
        Route::post('levels', [EmployeeLevelController::class, 'store']);
        Route::put('levels/{level}', [EmployeeLevelController::class, 'update']);
        Route::delete('levels/{level}', [EmployeeLevelController::class, 'destroy']);

        Route::get('allowance-policies', [AllowanceController::class, 'policies']);
        Route::post('allowance-policies/preview', [AllowanceController::class, 'previewPolicy']);
        Route::post('allowance-policies', [AllowanceController::class, 'storePolicy']);
        Route::get('allowance-overrides', [AllowanceController::class, 'overrides']);
        Route::post('allowance-overrides/preview', [AllowanceController::class, 'previewOverride']);
        Route::post('allowance-overrides', [AllowanceController::class, 'storeOverride']);
        Route::get('allowances', [AllowanceController::class, 'recap']);

        Route::get('leave-settings', [LeaveController::class, 'settings']);
        Route::get('leave-types', [LeaveTypeController::class, 'index']);
        Route::post('leave-types', [LeaveTypeController::class, 'store']);
        Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->whereNumber('leaveType');
        Route::get('leave-reasons', [LeaveReasonController::class, 'index']);
        Route::post('leave-reasons', [LeaveReasonController::class, 'store']);
        Route::put('leave-reasons/{leaveReason}', [LeaveReasonController::class, 'update'])->whereNumber('leaveReason');
        Route::get('leave-balances', [LeaveController::class, 'balances']);
        Route::put('leave-settings', [LeaveController::class, 'updateSettings']);
        Route::post('leave-settings/confirm', [LeaveController::class, 'confirmOpening']);
        Route::post('leave-quota/{employee}/adjustments', [LeaveController::class, 'adjust']);
        Route::get('leaves', [LeaveController::class, 'index']);
        Route::post('leaves/direct', [LeaveController::class, 'direct']);
        Route::post('leaves/{leave}/decision', [LeaveController::class, 'decide']);
        Route::post('leaves/{leave}/cancel-approved', [LeaveController::class, 'cancelApproved']);

        Route::get('schedule', [ScheduleController::class, 'index']);
        Route::post('schedule/swap/preview', [ScheduleController::class, 'previewSwap']);
        Route::post('schedule/swap', [ScheduleController::class, 'swap']);
        Route::post('schedule', [ScheduleController::class, 'store']);
        Route::post('schedule/bulk', [ScheduleController::class, 'bulk']);
        Route::patch('schedule/{assignment}/sessions', [ScheduleController::class, 'updateSessions']);
        Route::delete('schedule/{assignment}', [ScheduleController::class, 'destroy']);
        Route::post('schedule/publish', [ScheduleController::class, 'publish']);
        Route::post('schedule/copy-week', [ScheduleController::class, 'copyWeek']);
        Route::post('schedule/copy-month', [ScheduleController::class, 'copyMonth']);

        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
        Route::post('users/{user}/link-employee', [UserController::class, 'linkEmployee']);
        Route::post('users/{user}/unlink-employee', [UserController::class, 'unlinkEmployee']);
        Route::post('users/{user}/create-employee', [UserController::class, 'createEmployee']);
    });
});
