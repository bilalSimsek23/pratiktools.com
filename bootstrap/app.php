<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RejectAdminHost;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustCloudProxies;
use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::middleware([])->group(base_path('routes/public.php'));

            Route::middleware('web')
                ->domain(config('platform.admin_domain'))
                ->group(base_path('routes/admin.php'));

            // Tenant marketing panel (session + CSRF), never on the admin host.
            Route::middleware(['web', RejectAdminHost::class])
                ->prefix('app')
                ->name('panel.')
                ->group(base_path('routes/panel.php'));

            // Product root: the panel (login when signed out, dashboard when signed in).
            Route::middleware(['web', RejectAdminHost::class])
                ->get('/', fn () => redirect()->route('panel.dashboard'))
                ->name('home');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustCloudProxies::class);
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->api(prepend: [RejectAdminHost::class]);

        $middleware->alias(['tenant' => ResolveTenantContext::class]);

        // Tenant context must exist before route model binding resolves
        // tenant-scoped models.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantContext::class);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : ($request->is('app', 'app/*') ? route('panel.login') : route('admin.login')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*', 'health*') || $request->expectsJson(),
        );

        $exceptions->dontFlash(['secret', 'password', 'password_confirmation', 'current_password', 'code', 'token']);

        // Never reveal internals; the exception is still reported.
        $exceptions->render(function (TenantContextMissing $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => 'Server Error'], 500)
                : null;
        });
    })->create();
