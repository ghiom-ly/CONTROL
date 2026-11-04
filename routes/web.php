<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\SettingController;
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
