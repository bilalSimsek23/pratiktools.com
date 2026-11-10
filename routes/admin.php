<?php

use App\Http\Controllers\Admin\ActivationController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ConfirmController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RequestDiagnosticsController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantMemberController;
use App\Http\Controllers\Admin\TenantSesController;
use App\Http\Controllers\Admin\TenantTicimaxController;
use App\Http\Controllers\Admin\TenantViaController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PlatformRecentAuth;
use Illuminate\Support\Facades\Route;

/*
| Super Admin panel, served only on config('platform.admin_domain').
| Uses the "platform" guard and the platform database role; no tenant
| context is ever set here.
*/

Route::name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login.attempt');

    // Super Admin activation (platform:admin:invite): signed, single-use link. The
    // signature is checked in the controller so every bad link answers 410.
    Route::get('activate/{token}', [ActivationController::class, 'show'])->where('token', '[^/]{1,128}')->middleware('throttle:admin-activation')->name('activate');
    Route::post('activate/{token}', [ActivationController::class, 'activate'])->where('token', '[^/]{1,128}')->middleware('throttle:admin-activation')->name('activate.attempt');

    Route::middleware('auth:platform')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
        Route::post('two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:admin-login')->name('two-factor.confirm');
        Route::get('two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:admin-login')->name('two-factor.verify');

        Route::middleware(EnsurePlatformTwoFactor::class)->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::get('diagnostics/request', RequestDiagnosticsController::class)->name('diagnostics.request');
            Route::get('system', SystemController::class)->name('system');
            Route::get('confirm', [ConfirmController::class, 'show'])->name('confirm');
            Route::post('confirm', [ConfirmController::class, 'confirm'])->middleware('throttle:admin-login')->name('confirm.attempt');

            // Müşteriler (tenants). Every change needs a recent password confirmation.
            Route::get('tenants', [TenantController::class, 'index'])->name('tenants');
            Route::get('tenants/new', [TenantController::class, 'create'])->name('tenants.create');
            Route::get('tenants/{tenant}', [TenantController::class, 'show'])->whereUuid('tenant')->name('tenants.show');
            Route::get('tenants/{tenant}/integrations/via', [TenantViaController::class, 'show'])->whereUuid('tenant')->name('tenants.via');
            Route::get('tenants/{tenant}/integrations/ticimax', [TenantTicimaxController::class, 'show'])->whereUuid('tenant')->name('tenants.ticimax');
            Route::get('tenants/{tenant}/integrations/ses', [TenantSesController::class, 'show'])->whereUuid('tenant')->name('tenants.ses');
            Route::middleware(PlatformRecentAuth::class)->group(function () {
                Route::post('system/email-sending-mode', [SystemController::class, 'emailSendingMode'])->middleware('throttle:admin-email-mode')->name('system.email-sending-mode');
                Route::post('tenants', [TenantController::class, 'store'])->middleware('throttle:20,1')->name('tenants.store');
                Route::post('tenants/{tenant}/status', [TenantController::class, 'status'])->whereUuid('tenant')->name('tenants.status');
                Route::post('tenants/{tenant}/note', [TenantController::class, 'note'])->whereUuid('tenant')->name('tenants.note');
                Route::post('tenants/{tenant}/metadata', [TenantController::class, 'metadata'])->whereUuid('tenant')->name('tenants.metadata');
                Route::post('tenants/{tenant}/integrations/via', [TenantViaController::class, 'update'])->whereUuid('tenant')->name('tenants.via.update');
                Route::post('tenants/{tenant}/integrations/via/status', [TenantViaController::class, 'status'])->whereUuid('tenant')->name('tenants.via.status');
                Route::post('tenants/{tenant}/integrations/via/domain-activation', [TenantViaController::class, 'domainActivation'])->whereUuid('tenant')->name('tenants.via.domain-activation');
                Route::post('tenants/{tenant}/integrations/ticimax', [TenantTicimaxController::class, 'update'])->whereUuid('tenant')->middleware('throttle:20,1')->name('tenants.ticimax.update');
                Route::post('tenants/{tenant}/integrations/ticimax/test', [TenantTicimaxController::class, 'test'])->whereUuid('tenant')->middleware('throttle:6,1')->name('tenants.ticimax.test');
                Route::post('tenants/{tenant}/integrations/ticimax/onboarding', [TenantTicimaxController::class, 'onboarding'])->whereUuid('tenant')->middleware('throttle:20,1')->name('tenants.ticimax.onboarding');
                Route::post('tenants/{tenant}/integrations/ticimax/sync/{step}', [TenantTicimaxController::class, 'sync'])->whereUuid('tenant')
                    ->whereIn('step', ['categories', 'products', 'customers', 'orders', 'carts', 'consent_build', 'ticimax_history'])->middleware('throttle:20,1')->name('tenants.ticimax.sync');
                Route::prefix('tenants/{tenant}/integrations/ses')->whereUuid('tenant')->name('tenants.ses.')->controller(TenantSesController::class)->middleware('throttle:admin-ses')->group(function () {
                    Route::post('/', 'update')->name('update');
                    Route::post('test', 'test')->name('test');
                    Route::post('senders', 'storeSender')->name('senders.store');
                    Route::post('senders/{sender}/refresh', 'refreshSender')->whereUuid('sender')->name('senders.refresh');
                    Route::post('sns/confirm', 'confirmSubscription')->name('sns.confirm');
                    Route::post('scope', 'scope')->name('scope');
                    Route::post('test-recipients', 'addTestRecipient')->name('test-recipients.store');
                    Route::post('test-recipients/remove', 'removeTestRecipient')->name('test-recipients.remove');
                    Route::post('test-email', 'sendTest')->name('test-email');
                });
                Route::post('tenants/{tenant}/members', [TenantMemberController::class, 'store'])->whereUuid('tenant')->middleware('throttle:30,1')->name('tenants.members.store');
                Route::post('tenants/{tenant}/members/{member}/role', [TenantMemberController::class, 'role'])->whereUuid(['tenant', 'member'])->name('tenants.members.role');
                Route::post('tenants/{tenant}/members/{member}/status', [TenantMemberController::class, 'status'])->whereUuid(['tenant', 'member'])->name('tenants.members.status');
                Route::post('tenants/{tenant}/members/{member}/invitation', [TenantMemberController::class, 'reissue'])->whereUuid(['tenant', 'member'])->middleware('throttle:30,1')->name('tenants.members.invitation');
            });
        });
    });
});
