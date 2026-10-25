<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\IntegrationSyncController;
use App\Http\Controllers\Api\V1\LeadFormController;
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
        Route::get('integrations/{integration}/push-metrics', [IntegrationSyncController::class, 'pushMetrics']);
        Route::get('integrations/{integration}/email-metrics', [IntegrationSyncController::class, 'emailMetrics']);

        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);

        Route::get('consent/overview', [ConsentController::class, 'overview']);
        Route::get('consent/reconciliation', [ConsentController::class, 'reconciliation']);
        Route::get('consent/conflicts', [ConsentController::class, 'conflicts']);
        Route::get('customers/{customer}/eligibility', [ConsentController::class, 'eligibility']);

        Route::get('audit-logs', [AuditLogController::class, 'index']);

        Route::get('automations', [AutomationController::class, 'index']);
        Route::get('automations/{automation}', [AutomationController::class, 'show'])->whereUuid('automation');
        Route::post('automations/{automation}/dry-run', [AutomationController::class, 'dryRun'])->whereUuid('automation')->middleware('throttle:6,1');

        Route::get('campaigns', [CampaignController::class, 'index']);
        Route::post('campaigns', [CampaignController::class, 'store']);
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereUuid('campaign');
        Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->whereUuid('campaign');
        Route::post('campaigns/{campaign}/transition', [CampaignController::class, 'transition'])->whereUuid('campaign');
        Route::get('campaigns/{campaign}/audience', [CampaignController::class, 'audience'])->whereUuid('campaign')->middleware('throttle:12,1');
        Route::post('campaigns/{campaign}/dry-run', [CampaignController::class, 'dryRun'])->whereUuid('campaign')->middleware('throttle:6,1');

        Route::get('email-templates', [ContentController::class, 'templates']);
        Route::post('email-templates', [ContentController::class, 'createTemplate']);
        Route::post('email-templates/{template}/versions', [ContentController::class, 'addVersion'])->whereUuid('template');
        Route::post('email-templates/{template}/archive', [ContentController::class, 'archive'])->whereUuid('template');
        Route::post('email-template-versions/{version}/publish', [ContentController::class, 'publish'])->whereUuid('version');
        Route::get('email-template-versions/{version}/preview', [ContentController::class, 'preview'])->whereUuid('version');
        Route::get('email-senders', [ContentController::class, 'senders']);

        Route::get('lead-forms', [LeadFormController::class, 'index']);
        Route::post('lead-forms', [LeadFormController::class, 'store']);
        Route::post('lead-forms/{form}/active', [LeadFormController::class, 'setActive'])->whereUuid('form');
        Route::put('lead-forms/{form}/consent-text', [LeadFormController::class, 'consentText'])->whereUuid('form');
        Route::get('leads/report', [LeadFormController::class, 'report']);
    });
});
