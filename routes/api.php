<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\IntegrationSyncController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

/*
| Tenant API. The tenant of every authenticated request comes from the
| tenant its token was issued for (middleware "tenant"), never from input.
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('tenant', [TenantController::class, 'show']);
        Route::patch('tenant/settings', [TenantController::class, 'updateSettings']);

        Route::get('members', [MemberController::class, 'index']);
        Route::patch('members/{member}/role', [MemberController::class, 'updateRole']);

        Route::get('integrations', [IntegrationController::class, 'index']);
        Route::post('integrations', [IntegrationController::class, 'store']);
        Route::get('integrations/{integration}', [IntegrationController::class, 'show']);
        Route::put('integrations/{integration}/secret', [IntegrationController::class, 'updateSecret']);
        Route::post('integrations/{integration}/verify', [IntegrationController::class, 'verify']);
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy']);
        Route::post('integrations/{integration}/test', [IntegrationSyncController::class, 'test'])->middleware('throttle:6,1');
        Route::get('integrations/{integration}/sync-status', [IntegrationSyncController::class, 'status']);
        Route::post('integrations/{integration}/sync', [IntegrationSyncController::class, 'sync'])->middleware('throttle:6,1');
        Route::get('integrations/{integration}/preview', [IntegrationSyncController::class, 'preview']);

        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);

        Route::get('consent/overview', [ConsentController::class, 'overview']);
        Route::get('consent/reconciliation', [ConsentController::class, 'reconciliation']);
        Route::get('consent/conflicts', [ConsentController::class, 'conflicts']);
        Route::get('customers/{customer}/eligibility', [ConsentController::class, 'eligibility']);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
    });
});
