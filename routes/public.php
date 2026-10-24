<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\UnsubscribeController;
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

// Signed unsubscribe links: GET confirms, POST (incl. RFC 8058 one-click) applies.
Route::get('u/{token}', [UnsubscribeController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]{16,400}\.[A-Za-z0-9_-]{43}')
    ->middleware([RejectAdminHost::class, 'throttle:unsubscribe'])
    ->name('email.unsubscribe.show');
Route::post('u/{token}', [UnsubscribeController::class, 'apply'])
    ->where('token', '[A-Za-z0-9_-]{16,400}\.[A-Za-z0-9_-]{43}')
    ->middleware([RejectAdminHost::class, 'throttle:unsubscribe'])
    ->name('email.unsubscribe.apply');
