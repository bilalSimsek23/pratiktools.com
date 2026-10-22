<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use Illuminate\Support\Facades\Route;

/*
| Super Admin panel, served only on config('platform.admin_domain').
| Uses the "platform" guard and the platform database role; no tenant
| context is ever set here.
*/

Route::name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login.attempt');

    Route::middleware('auth:platform')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
        Route::post('two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:admin-login')->name('two-factor.confirm');
        Route::get('two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:admin-login')->name('two-factor.verify');

        Route::middleware(EnsurePlatformTwoFactor::class)->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
        });
    });
});
