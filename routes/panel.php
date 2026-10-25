<?php

use App\Http\Controllers\Panel\AuthController;
use App\Http\Controllers\Panel\CampaignController;
use App\Http\Controllers\Panel\ContentController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\DispatchController;
use App\Http\Controllers\Panel\LeadFormController;
use App\Http\Controllers\Panel\OperationsController;
use App\Http\Controllers\Panel\SegmentAutomationController;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use Illuminate\Support\Facades\Route;

/*
| Tenant marketing panel. Session guard "web", CSRF on every POST, tenant
| from the session (chosen at login, membership re-checked per request).
| Every action is authorized in the backend; sensitive ones also need a
| recent password confirmation. Nothing here can enable live sending.
*/

Route::get('login', [AuthController::class, 'show'])->name('login');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.attempt');

Route::middleware('auth:web')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('tenants', [AuthController::class, 'tenants'])->name('tenants');
    Route::post('tenants', [AuthController::class, 'chooseTenant'])->name('tenants.choose');
    Route::get('confirm', [AuthController::class, 'confirmForm'])->name('confirm');
    Route::post('confirm', [AuthController::class, 'confirm'])->middleware('throttle:login')->name('confirm.attempt');

    Route::middleware(PanelTenant::class)->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('notifications/{id}/read', [OperationsController::class, 'readNotification'])->whereUuid('id')->name('notifications.read');

        Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns');
        Route::get('campaigns/new', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereUuid('campaign')->name('campaigns.show');
        Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->whereUuid('campaign')->name('campaigns.edit');
        Route::post('campaigns/{campaign}', [CampaignController::class, 'update'])->whereUuid('campaign')->name('campaigns.update');
        Route::post('campaigns/{campaign}/dry-run', [CampaignController::class, 'dryRun'])->whereUuid('campaign')->middleware('throttle:6,1')->name('campaigns.dry-run');
        Route::post('campaigns/{campaign}/transition', [CampaignController::class, 'transition'])->whereUuid('campaign')->middleware(PanelRecentAuth::class)->name('campaigns.transition');
        Route::post('campaigns/{campaign}/reopen', [CampaignController::class, 'reopen'])->whereUuid('campaign')->middleware(PanelRecentAuth::class)->name('campaigns.reopen');
        Route::post('campaigns/{campaign}/approvals', [CampaignController::class, 'requestApproval'])->whereUuid('campaign')->name('campaigns.approvals.request');
        Route::post('campaigns/{campaign}/approvals/{approval}', [CampaignController::class, 'decide'])->whereUuid(['campaign', 'approval'])->middleware(PanelRecentAuth::class)->name('campaigns.approvals.decide');
        Route::get('approvals', [CampaignController::class, 'approvals'])->name('approvals');

        Route::get('dispatches', [DispatchController::class, 'index'])->name('dispatches');
        Route::get('campaigns/{campaign}/dispatches/new', [DispatchController::class, 'create'])->whereUuid('campaign')->name('dispatches.create');
        Route::post('campaigns/{campaign}/dispatches', [DispatchController::class, 'store'])->whereUuid('campaign')->name('dispatches.store');
        Route::get('dispatches/{dispatch}', [DispatchController::class, 'show'])->whereUuid('dispatch')->name('dispatches.show');
        Route::post('dispatches/{dispatch}/queue', [DispatchController::class, 'queue'])->whereUuid('dispatch')->middleware(PanelRecentAuth::class)->name('dispatches.queue');
        Route::post('dispatches/{dispatch}/cancel', [DispatchController::class, 'cancel'])->whereUuid('dispatch')->name('dispatches.cancel');

        Route::get('templates', [ContentController::class, 'index'])->name('templates');
        Route::post('templates', [ContentController::class, 'store'])->name('templates.store');
        Route::get('templates/{template}', [ContentController::class, 'show'])->whereUuid('template')->name('templates.show');
        Route::post('templates/{template}/versions', [ContentController::class, 'storeVersion'])->whereUuid('template')->name('templates.versions.store');
        Route::post('templates/{template}/archive', [ContentController::class, 'archive'])->whereUuid('template')->middleware(PanelRecentAuth::class)->name('templates.archive');
        Route::post('template-versions/{version}/publish', [ContentController::class, 'publish'])->whereUuid('version')->middleware(PanelRecentAuth::class)->name('templates.publish');
        Route::get('template-versions/{version}/frame', [ContentController::class, 'frame'])->whereUuid('version')->name('templates.frame');
        Route::get('senders', [ContentController::class, 'senders'])->name('senders');

        Route::get('segments', [SegmentAutomationController::class, 'segments'])->name('segments');
        Route::post('segments', [SegmentAutomationController::class, 'storeSegment'])->name('segments.store');
        Route::post('segments/preview', [SegmentAutomationController::class, 'previewSegment'])->middleware('throttle:12,1')->name('segments.preview');
        Route::get('automations', [SegmentAutomationController::class, 'automations'])->name('automations');
        Route::get('automations/{automation}', [SegmentAutomationController::class, 'automation'])->whereUuid('automation')->name('automations.show');
        Route::post('automations', [SegmentAutomationController::class, 'storeAutomation'])->name('automations.store');
        Route::post('automations/{automation}/dry-run', [SegmentAutomationController::class, 'dryRunAutomation'])->whereUuid('automation')->middleware('throttle:6,1')->name('automations.dry-run');
        Route::post('automations/{automation}/state', [SegmentAutomationController::class, 'automationState'])->whereUuid('automation')->middleware(PanelRecentAuth::class)->name('automations.state');

        Route::get('forms', [LeadFormController::class, 'index'])->name('forms');
        Route::post('forms', [LeadFormController::class, 'store'])->name('forms.store');
        Route::get('forms/{form}', [LeadFormController::class, 'show'])->whereUuid('form')->name('forms.show');
        Route::post('forms/{form}/active', [LeadFormController::class, 'active'])->whereUuid('form')->middleware(PanelRecentAuth::class)->name('forms.active');
        Route::post('forms/{form}/consent', [LeadFormController::class, 'consent'])->whereUuid('form')->middleware(PanelRecentAuth::class)->name('forms.consent');
        Route::get('leads', [LeadFormController::class, 'leads'])->name('leads');

        Route::get('integrations', [OperationsController::class, 'integrations'])->name('integrations');
        Route::get('readiness', [OperationsController::class, 'readiness'])->name('readiness');
        Route::get('suppressions', [OperationsController::class, 'suppressions'])->name('suppressions');
        Route::get('settings', [OperationsController::class, 'settings'])->name('settings');
        Route::post('settings/sending-mode', [OperationsController::class, 'sendingMode'])->middleware(PanelRecentAuth::class)->name('settings.sending-mode');
    });
});
