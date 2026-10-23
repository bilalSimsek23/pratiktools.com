<?php

namespace Tests\Concerns;

use App\Commerce\CommerceSourceFactory;
use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Commerce\Sync\SyncRunner;
use App\Models\IntegrationSyncRun;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Security\OutboundUrlGuard;
use Tests\Support\FakeTicimax;

/**
 * Wires FakeTicimax transports per service URL, so each tenant can have its
 * own fake store, and keeps the tests free of DNS and real sleeps.
 */
trait InteractsWithTicimax
{
    /** @var array<string, FakeTicimax> service URL => fake */
    protected array $fakeStores = [];

    protected function setUpTicimax(): void
    {
        config([
            'commerce.ticimax.base_backoff_ms' => 1,
            'commerce.ticimax.member_page_size' => 2,
            'commerce.ticimax.product_page_size' => 2,
            'commerce.ticimax.order_page_size' => 2,
            'platform.rate_limits.providers.ticimax.tenant' => ['capacity' => 10000, 'refill_per_second' => 10000],
        ]);

        $this->app->instance(OutboundUrlGuard::class, new class extends OutboundUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['203.0.113.10'];
            }
        });
        $this->app->forgetInstance(CommerceSourceFactory::class);
        $this->app->make(CommerceSourceFactory::class)->useTransport(fn (string $url) => $this->fakeStores[$url] ?? throw new \LogicException("No fake store for {$url}"));
    }

    protected function connectTicimax(Tenant $tenant, ?FakeTicimax $store = null, string $secret = 'valid-member-code', string $status = 'connected'): TenantIntegration
    {
        $serviceUrl = "https://{$tenant->slug}.example.com/Servis/";
        $this->fakeStores[$serviceUrl] = $store ?? new FakeTicimax;

        $integration = $this->createIntegration($tenant, $secret, 'ticimax', 'commerce');

        return $this->inTenant($tenant, function () use ($integration, $serviceUrl, $tenant, $status) {
            $integration->forceFill([
                'settings' => ['service_url' => $serviceUrl, 'storefront_url' => "https://{$tenant->slug}.example.com"],
                'status' => $status,
            ])->save();

            return $integration;
        });
    }

    protected function store(Tenant $tenant): FakeTicimax
    {
        return $this->fakeStores["https://{$tenant->slug}.example.com/Servis/"];
    }

    protected function sync(Tenant $tenant, TenantIntegration $integration, SyncEntity $entity, SyncMode $mode = SyncMode::Full, ?float $slice = null): IntegrationSyncRun
    {
        return $this->inTenant($tenant, fn () => app(SyncRunner::class)->run($integration->fresh(), $entity, $mode, $slice));
    }
}
