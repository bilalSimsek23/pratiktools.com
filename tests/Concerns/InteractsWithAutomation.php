<?php

namespace Tests\Concerns;

use App\Audit\Actor;
use App\Automation\AutomationEngine;
use App\Automation\Automations;
use App\Automation\AutomationTemplates;
use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\RecipientDirectory;
use App\Consent\RecipientType;
use App\Consent\Sync\ConsentSynchronizer;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Commerce, consent and push fixtures for automation tests, written the
 * way the F1–F3 syncs write them (events through the EventRecorder).
 */
trait InteractsWithAutomation
{
    protected function iysSynced(Tenant $tenant): void
    {
        $this->inTenant($tenant, function () use ($tenant) {
            $integration = DB::table('tenant_integrations')->where('provider', 'iys')->value('id')
                ?? $this->createIntegration($tenant, (string) json_encode(['username' => 'u', 'password' => 'p']), 'iys', 'consent')->id;
            DB::table('integration_sync_cursors')->insertOrIgnore(['tenant_id' => $tenant->id, 'integration_id' => $integration, 'entity' => ConsentSynchronizer::ENTITY_IYS_STATUS,
                'last_success_at' => now(), 'last_window_end' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * A customer with an e-mail consent recipient; optional İYS status.
     */
    protected function shopper(Tenant $tenant, string $email, ?ConsentStatus $iys = ConsentStatus::Granted, ?string $sharedWith = null): string
    {
        return $this->inTenant($tenant, function () use ($tenant, $email, $iys) {
            $id = (string) Str::uuid7();
            DB::table('customers')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'email' => $email, 'first_name' => 'Ayşe', 'created_at' => now(), 'updated_at' => now()]);
            $directory = app(RecipientDirectory::class);
            $normalized = $directory->email($email);
            $recipient = $directory->ensure('email', [$normalized])[$normalized['address']];
            $directory->link([['recipient_id' => $recipient, 'customer_id' => $id, 'link_source' => 'test']]);
            if ($iys !== null && $iys !== ConsentStatus::Unknown) {
                $this->iys($tenant, $email, $iys);
            }

            return $id;
        });
    }

    protected function iys(Tenant $tenant, string $email, ConsentStatus $status): void
    {
        $this->inTenant($tenant, fn () => app(ConsentStore::class)->observe(
            (string) DB::table('consent_recipients')->where('address', strtolower($email))->value('id'), ConsentChannel::Email, RecipientType::Individual,
            ConsentSourceProvider::Iys, $status, CarbonImmutable::now(), 'test:'.Str::random(10), consentDate: CarbonImmutable::parse('2025-01-10')));
    }

    protected function product(Tenant $tenant, bool $active = true, float $stock = 5): string
    {
        return $this->inTenant($tenant, function () use ($tenant, $active, $stock) {
            $id = (string) Str::uuid7();
            DB::table('products')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'external_id' => 'P'.Str::random(8), 'name' => 'Ceket', 'is_active' => $active,
                'total_stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    /**
     * A cart with items and its CART_UPDATED event (as CartUpserter writes them).
     *
     * @param  list<string>  $productIds
     */
    protected function cart(Tenant $tenant, ?string $customerId, array $productIds, ?string $externalId = null, string $hash = 'h1', ?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();

        return $this->inTenant($tenant, function () use ($tenant, $customerId, $productIds, $externalId, $hash, $at) {
            $externalId ??= 'C'.Str::random(8);
            $existing = DB::table('carts')->where('external_id', $externalId)->value('id');
            $id = $existing ?? (string) Str::uuid7();
            $values = ['customer_id' => $customerId, 'item_count' => count($productIds), 'total' => 999.9, 'content_hash' => $hash, 'source_created_at' => $at, 'last_seen_at' => $at, 'updated_at' => $at];
            $existing === null
                ? DB::table('carts')->insert($values + ['id' => $id, 'tenant_id' => $tenant->id, 'external_id' => $externalId, 'created_at' => $at])
                : DB::table('carts')->where('id', $id)->update($values);
            DB::table('cart_items')->where('cart_id', $id)->delete();
            foreach ($productIds as $p) {
                DB::table('cart_items')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'cart_id' => $id, 'external_id' => 'I'.Str::random(8), 'product_id' => $p,
                    'quantity' => 1, 'unit_price' => 499.95, 'created_at' => $at, 'updated_at' => $at]);
            }
            app(EventRecorder::class)->record(CustomerEventName::CartUpdated, $customerId, $at, 'ticimax', "cart:{$externalId}:{$hash}",
                ['cart_id' => $id, 'item_count' => count($productIds), 'total' => 999.9]);

            return $id;
        });
    }

    protected function order(Tenant $tenant, string $customerId, CarbonImmutable $placedAt): void
    {
        $this->inTenant($tenant, fn () => DB::table('orders')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'external_id' => 'O'.Str::random(8),
            'customer_id' => $customerId, 'channel' => 'web', 'placed_at' => $placedAt, 'grand_total' => 100, 'created_at' => now(), 'updated_at' => now()]));
    }

    protected function stats(Tenant $tenant, string $customerId, CarbonImmutable $lastOrder, int $orders = 1, float $revenue = 1000): void
    {
        $this->inTenant($tenant, fn () => DB::table('customer_stats')->upsert([['customer_id' => $customerId, 'tenant_id' => $tenant->id, 'order_count' => $orders,
            'lifetime_value' => $revenue, 'average_order_value' => $revenue / max(1, $orders), 'first_order_at' => $lastOrder->subYear(), 'last_order_at' => $lastOrder, 'computed_at' => now()]],
            ['customer_id'], ['order_count', 'lifetime_value', 'last_order_at']));
    }

    protected function pushSubscriber(Tenant $tenant, string $customerId, string $status = 'subscribed'): string
    {
        return $this->inTenant($tenant, function () use ($tenant, $customerId, $status) {
            $integration = DB::table('tenant_integrations')->where('provider', 'onesignal')->value('id')
                ?? $this->createIntegration($tenant, 'os_v2_app_test_key_0000000000', 'onesignal', 'push')->id;
            $profile = (string) Str::uuid7();
            DB::table('push_profiles')->insert(['id' => $profile, 'tenant_id' => $tenant->id, 'integration_id' => $integration, 'provider' => 'onesignal', 'provider_user_id' => (string) Str::uuid(),
                'external_id' => '1001', 'customer_id' => $customerId, 'identity_status' => 'resolved', 'subscription_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $sub = (string) Str::uuid7();
            DB::table('push_subscriptions')->insert(['id' => $sub, 'tenant_id' => $tenant->id, 'integration_id' => $integration, 'push_profile_id' => $profile, 'provider' => 'onesignal',
                'provider_subscription_id' => (string) Str::uuid(), 'platform' => 'android', 'device_type' => 1, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

            return $sub;
        });
    }

    protected function abandonedCartAutomation(Tenant $tenant, int $delayMinutes = 60, bool $publish = true): string
    {
        return $this->inTenant($tenant, function () use ($delayMinutes, $publish) {
            $d = AutomationTemplates::abandonedCart($delayMinutes);
            $id = app(Automations::class)->create('Sepet hatırlatma', $d['trigger'], $d['steps'], $d['settings'], Actor::system(), 'abandoned_cart');
            if ($publish) {
                app(Automations::class)->publish($id, Actor::system());
            }

            return $id;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function tick(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        return $this->inTenant($tenant, fn () => app(AutomationEngine::class)->tick(now: $now));
    }

    protected function runs(Tenant $tenant): Collection
    {
        return $this->inTenant($tenant, fn () => DB::table('automation_runs')->orderBy('started_at')->get());
    }

    protected function intents(Tenant $tenant): Collection
    {
        return $this->inTenant($tenant, fn () => DB::table('automation_planned_actions')->orderBy('created_at')->get());
    }
}
