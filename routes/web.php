<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\LegacyImportController;
use App\Http\Controllers\OpenCodesController;
use App\Http\Controllers\ReportReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShiftImportController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\SettingController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    Route::middleware('password.fresh')->group(function () {
        Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');

        Route::get('/search', SearchController::class)->name('search');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

        // ---- الموظفون (المرحلة 1)
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('/users/{id}/restore', [UserController::class, 'restore'])->whereNumber('id')->name('users.restore');

        // ---- المتاجر (المرحلة 2)
        Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
        Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
        Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
        Route::put('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::patch('/stores/{store}/archive', [StoreController::class, 'archive'])->name('stores.archive');

        // ---- التقارير اليومية (المرحلة 2)
        Route::get('/reports', [DailyReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/new', [DailyReportController::class, 'create'])->name('reports.create');
        Route::post('/reports/start', [DailyReportController::class, 'start'])->name('reports.start');
        Route::get('/reports/{report}/edit', [DailyReportController::class, 'edit'])->name('reports.edit');
        Route::put('/reports/{report}', [DailyReportController::class, 'update'])->name('reports.update');
        Route::get('/reports/{report}', [DailyReportController::class, 'show'])->name('reports.show');
        Route::post('/reports/{report}/submit', [DailyReportController::class, 'submit'])->name('reports.submit');
        Route::get('/review', [ReportReviewController::class, 'queue'])->name('reports.review.queue');
        Route::post('/reports/{report}/approve', [ReportReviewController::class, 'approve'])->name('reports.approve');
        Route::post('/reports/{report}/reject', [ReportReviewController::class, 'reject'])->name('reports.reject');
        Route::post('/reports/{report}/attachments', [AttachmentController::class, 'store'])->name('reports.attachments.store');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

        // ---- الاستيراد (المرحلة 3)
        Route::get('/imports/legacy', [LegacyImportController::class, 'create'])->name('imports.legacy.create');
        Route::post('/imports/legacy/preview', [LegacyImportController::class, 'preview'])->name('imports.legacy.preview');
        Route::get('/imports/legacy/review', [LegacyImportController::class, 'review'])->name('imports.legacy.review');
        Route::post('/imports/legacy', [LegacyImportController::class, 'store'])->name('imports.legacy');
        Route::post('/imports/shift', [ShiftImportController::class, 'upload'])->name('imports.shift.upload');
        Route::get('/imports/{batch}', [ShiftImportController::class, 'show'])->whereNumber('batch')->name('imports.show');
        Route::post('/imports/{batch}/mapping', [ShiftImportController::class, 'mapping'])->name('imports.mapping');
        Route::post('/imports/{batch}/apply', [ShiftImportController::class, 'apply'])->name('imports.apply');
        Route::post('/imports/{batch}/cancel', [ShiftImportController::class, 'cancel'])->name('imports.cancel');

        // ---- الأكواد المفتوحة (المرحلة 2)
        Route::get('/open-codes', [OpenCodesController::class, 'index'])->name('open-codes.index');
        Route::post('/open-codes/mark', [OpenCodesController::class, 'mark'])->name('open-codes.mark');

        // ---- الإعدادات والصلاحيات
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('/settings/roles', [RoleController::class, 'index'])->name('settings.roles');
        Route::put('/settings/roles', [RoleController::class, 'update'])->name('settings.roles.update');

        // ---- سجل العمليات
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');

        // ---- وحدات المراحل القادمة (صفحة توضيحية بدون بيانات وهمية)
        Route::get('/modules/{module}', ModulePlaceholderController::class)->name('modules.placeholder');
    });
});
