<?php

namespace App\Providers;

use App\Commerce\CommerceSourceFactory;
use App\Commerce\Identity\ContactNormalizer;
use App\Connectors\Iys\IysPushHandler;
use App\Connectors\Iys\IysPushVerifier;
use App\Consent\ConsentProviderFactory;
use App\Integrations\ProviderRegistry;
use App\Logging\Redactor;
use App\Models\PersonalAccessToken;
use App\Observability\ErrorReporter;
use App\Observability\NullErrorReporter;
use App\Observability\NullTracer;
use App\Observability\Tracer;
use App\Queue\QueueContextGuard;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\KeyEncryptionServiceFactory;
use App\Support\ProductionGuard;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDirectory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantDirectory::class);
        $this->app->singleton(QueueContextGuard::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ConsentProviderFactory::class);
        $this->app->resolving(ProviderRegistry::class, function (ProviderRegistry $registry, $app) {
            $registry->registerSignatureVerifier('iys', $app->make(IysPushVerifier::class));
            $registry->registerWebhookHandler('iys', $app->make(IysPushHandler::class));
        });
        $this->app->singleton(Redactor::class);
        $this->app->singleton(ErrorReporter::class, NullErrorReporter::class);
        $this->app->singleton(Tracer::class, NullTracer::class);
        $this->app->singleton(CommerceSourceFactory::class);
        $this->app->singleton(ContactNormalizer::class, fn () => new ContactNormalizer(config('commerce.marketplace_email_domains')));

        $this->app->singleton(KeyEncryptionService::class, fn () => KeyEncryptionServiceFactory::make(config('platform.vault'), $this->app->isProduction()));
    }

    public function boot(): void
    {
        ProductionGuard::assertSafe();

        Model::shouldBeStrict(! $this->app->isProduction());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Every new or re-established tenant connection receives the current
        // tenant context, so RLS never runs with a stale session setting.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if (in_array($event->connectionName, TenantContext::tenantConnections(), true)) {
                $this->app->make(TenantContext::class)->applyTo($event->connection);
            }
        });

        QueueContextGuard::register();

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (mixed $user = null) use ($permission): bool {
                $membership = $this->app->make(TenantContext::class)->membership();

                return $membership !== null
                    && $membership->isActive()
                    && $membership->user_id === $user?->getAuthIdentifier()
                    && $membership->role->allows($permission);
            });
        }

        $this->configureRateLimits();
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('email:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by('admin:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('admin-ip:'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by(
            'token:'.($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())
        ));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('webhook:'.$request->ip()));
    }
}
