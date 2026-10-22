<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\RejectAdminHost;
use Illuminate\Support\Facades\Route;

/*
| Stateless public endpoints: no session, no cookies, no CSRF.
*/

Route::get('healthz', [HealthController::class, 'live'])->name('health.live');
Route::get('health', [HealthController::class, 'ready'])->name('health.ready');

// Stored in the webhook inbox first, processed on the "webhooks" queue.
Route::post('webhooks/{provider}/{tenantPublicId}', WebhookController::class)
    ->where(['provider' => '[a-z0-9_-]{2,32}', 'tenantPublicId' => '[a-z0-9]{16,32}'])
    ->middleware([RejectAdminHost::class, 'throttle:webhooks'])
    ->name('webhooks.receive');
