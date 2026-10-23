<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Commerce\Sync\SyncRunner;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SoapFault;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

final class CommerceSyncTest extends TestCase
{
    use InteractsWithTenants, InteractsWithTicimax;

    private Tenant $tenant;

    private TenantIntegration $integration;

    private FakeTicimax $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTicimax();
        [$this->tenant] = $this->createTenant('shop');
        $this->integration = $this->connectTicimax($this->tenant);
        $this->shop = $this->store($this->tenant);
        $this->seedCatalog($this->shop);
    }

    private function seedCatalog(FakeTicimax $shop): void
    {
        $shop->categories = [FakeTicimax::category(1, 0, 'Kadın'), FakeTicimax::category(2, 1, 'Elbise'), FakeTicimax::category(3, 1, 'Ceket')];
        $shop->products = [
            FakeTicimax::product(5, [FakeTicimax::variant(50, 'Siyah', 'M'), FakeTicimax::variant(51, 'Siyah', 'L')], [1, 2]),
            FakeTicimax::product(6, [FakeTicimax::variant(60, 'Bej', 'S')], [3]),
            FakeTicimax::product(7, [FakeTicimax::variant(70, 'Lacivert', '38')], [3]),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return $this->inTenant($this->tenant, fn () => collect(['customers', 'customer_identities', 'orders', 'order_items', 'carts', 'cart_items', 'customer_events', 'products', 'product_variants', 'categories'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all());
    }

    private function syncAll(SyncMode $mode = SyncMode::Full): void
    {
        foreach (SyncEntity::ordered() as $entity) {
            $run = $this->sync($this->tenant, $this->integration, $entity, $mode);
            $this->assertSame('succeeded', $run->status, "{$entity->value}: {$run->error_message}");
        }
    }

    public function test_catalog_sync_links_categories_and_variants(): void
    {
        $this->sync($this->tenant, $this->integration, SyncEntity::Categories);
        $this->sync($this->tenant, $this->integration, SyncEntity::Products);

        $this->inTenant($this->tenant, function () {
            $kadin = DB::table('categories')->where('external_id', '1')->value('id');
            $this->assertSame($kadin, DB::table('categories')->where('external_id', '2')->value('parent_id'));
            $this->assertNull(DB::table('categories')->where('external_id', '1')->value('parent_id'));
            $this->assertSame(4, DB::table('product_variants')->count());
            $this->assertSame(0, DB::table('product_categories')->whereNull('category_id')->count());
            $this->assertSame('Siyah', DB::table('product_variants')->where('external_id', '50')->value('color'));
        });

        // A variant removed from the card is deactivated, not deleted.
        $this->shop->products[0] = FakeTicimax::product(5, [FakeTicimax::variant(50, 'Siyah', 'M')], [1, 2]);
        $run = $this->sync($this->tenant, $this->integration, SyncEntity::Products);

        $this->assertSame(1, $run->records_updated);
        $this->assertSame(2, $run->records_skipped);
        $this->inTenant($this->tenant, fn () => $this->assertFalse((bool) DB::table('product_variants')->where('external_id', '51')->value('is_active')));
    }

    public function test_a_card_returned_without_variants_never_deactivates_them(): void
    {
        $this->sync($this->tenant, $this->integration, SyncEntity::Products);

        // Ticimax stock-date queries return the card without Varyasyonlar.
        $this->shop->products[0] = FakeTicimax::product(5, [], [1, 2], ['ToplamStokAdedi' => 3, 'Varyasyonlar' => []]);
        $run = $this->sync($this->tenant, $this->integration, SyncEntity::Products, SyncMode::Delta);

        $this->assertSame('succeeded', $run->status);
        $this->inTenant($this->tenant, function () {
            $this->assertSame(2, DB::table('product_variants')->where('external_id', 'like', '5%')->where('is_active', true)->count());
            $this->assertEquals(3, (float) DB::table('products')->where('external_id', '5')->value('total_stock'));
            $this->assertSame(2, DB::table('product_categories')->join('products', 'products.id', '=', 'product_categories.product_id')->where('products.external_id', '5')->count());
        });
    }

    public function test_duplicate_member_id_across_pages_creates_one_customer(): void
    {
        $this->shop->members = [
            FakeTicimax::member(1, 'ayse@example.com'),
            FakeTicimax::member(2, 'mehmet@example.com'),
            FakeTicimax::member(1, 'ayse@example.com', ['Isim' => 'Ayşe']),
        ];

        $this->sync($this->tenant, $this->integration, SyncEntity::Customers);

        $this->inTenant($this->tenant, function () {
            $this->assertSame(2, DB::table('customers')->count());
            $this->assertSame(1, DB::table('customer_identities')->where('type', 'commerce_member')->where('value', '1')->count());
            $this->assertSame('Ayşe', DB::table('customers')->where('email', 'ayse@example.com')->value('first_name'));
            $this->assertSame('+905321112201', DB::table('customers')->where('email', 'ayse@example.com')->value('phone'));
        });
    }

    public function test_email_matching_is_deterministic_across_members_subscribers_and_guest_orders(): void
    {
        $this->shop->members = [FakeTicimax::member(1, 'Ayse@Example.com ')];
        $this->shop->subscribers = [FakeTicimax::subscriber(900, 'ayse@example.com'), FakeTicimax::subscriber(901, 'only-newsletter@example.com')];
        $this->shop->orders = [FakeTicimax::order(10, 0, 'AYSE@example.com', [FakeTicimax::orderItem(100, 5, 50)])];

        $this->syncAll();

        $this->inTenant($this->tenant, function () {
            $ayse = DB::table('customer_identities')->where('type', 'email')->where('value', 'ayse@example.com')->value('customer_id');
            $this->assertNotNull($ayse);
            $this->assertSame($ayse, DB::table('customer_identities')->where('type', 'newsletter_subscriber')->where('value', '900')->value('customer_id'));
            $this->assertSame($ayse, DB::table('orders')->where('external_id', '10')->value('customer_id'));
            $this->assertSame(2, DB::table('customers')->count(), 'Ayşe and the newsletter-only subscriber.');
            $this->assertTrue((bool) DB::table('customers')->where('email', 'only-newsletter@example.com')->value('is_guest'));
            $consent = json_decode(DB::table('customers')->where('id', $ayse)->value('source_consent'), true);
            $this->assertTrue($consent['email_opt_in']);
            $this->assertTrue($consent['newsletter']['email_opt_in']);
        });
    }

    public function test_email_owned_by_another_member_is_queued_for_review_not_merged(): void
    {
        $this->shop->members = [FakeTicimax::member(1, 'shared@example.com'), FakeTicimax::member(2, 'shared@example.com')];

        $this->sync($this->tenant, $this->integration, SyncEntity::Customers);

        $this->inTenant($this->tenant, function () {
            $this->assertSame(2, DB::table('customers')->count());
            $this->assertSame(1, DB::table('customer_merges')->where('status', 'pending_review')->count());
        });
    }

    public function test_marketplace_orders_are_stored_flagged_and_never_create_identities(): void
    {
        $this->shop->orders = [
            FakeTicimax::order(20, 0, 'abc123@trendyolmail.com', [FakeTicimax::orderItem(200, 5, 50)], ['SiparisKaynagi' => 'TRENDYOL']),
            FakeTicimax::order(21, 555, 'buyer@gmail.com', [FakeTicimax::orderItem(210, 6, 60)], ['SiparisKaynagi' => 'HepsiBurada', 'IsMarketplace' => true]),
            FakeTicimax::order(22, 0, 'relay@hepsifatura.com', [FakeTicimax::orderItem(220, 6, 60)], ['SiparisKaynagi' => 'TicimaxWeb']),
        ];

        $this->syncAll();

        $this->inTenant($this->tenant, function () {
            $this->assertSame(3, DB::table('orders')->count());
            $this->assertSame(2, DB::table('orders')->where('is_marketplace', true)->where('channel', 'marketplace')->count());
            $this->assertSame(0, DB::table('orders')->whereNotNull('customer_id')->count());
            $this->assertSame(0, DB::table('customers')->count());
            $this->assertSame(0, DB::table('customer_identities')->count());
            $this->assertSame('abc123@trendyolmail.com', DB::table('orders')->where('external_id', '20')->value('email'), 'Kept as source data.');
            $this->assertSame(3, DB::table('customer_events')->where('name', 'ORDER_PLACED')->whereNull('customer_id')->count());
        });
    }

    public function test_marketplace_order_links_to_an_existing_member_but_does_not_count_in_stats(): void
    {
        $this->shop->members = [FakeTicimax::member(1, 'ayse@example.com')];
        $this->shop->orders = [
            FakeTicimax::order(30, 1, 'ayse@example.com', [FakeTicimax::orderItem(300, 5, 50, net: 1000, tax: 100)]),
            FakeTicimax::order(31, 1, 'x@trendyolmail.com', [FakeTicimax::orderItem(310, 5, 50, net: 500, tax: 50)], ['SiparisKaynagi' => 'TRENDYOL']),
            FakeTicimax::order(32, 1, 'ayse@example.com', [FakeTicimax::orderItem(320, 6, 60, net: 2000, tax: 200)], ['SiparisDurumu' => 'İptal Edildi', 'Durum' => 9]),
        ];

        $this->syncAll();

        $this->inTenant($this->tenant, function () {
            $customer = DB::table('customer_identities')->where('type', 'commerce_member')->where('value', '1')->value('customer_id');
            $this->assertSame($customer, DB::table('orders')->where('external_id', '31')->value('customer_id'));
            $stats = DB::table('customer_stats')->where('customer_id', $customer)->first();
            $this->assertSame(1, (int) $stats->order_count);
            $this->assertEquals(1100, (float) $stats->lifetime_value);
            $this->assertEquals(1100, (float) $stats->average_order_value);
            $this->assertNotNull($stats->first_order_at);
            $this->assertNull(DB::table('customer_identities')->where('value', 'x@trendyolmail.com')->first());
        });
    }

    public function test_order_resync_is_idempotent(): void
    {
        $this->shop->members = [FakeTicimax::member(1, 'ayse@example.com')];
        $this->shop->orders = [FakeTicimax::order(10, 1, 'ayse@example.com', [FakeTicimax::orderItem(100, 5, 50), FakeTicimax::orderItem(101, 6, 60)])];

        $this->syncAll();
        $before = $this->counts();
        $run = $this->sync($this->tenant, $this->integration, SyncEntity::Orders);

        $this->assertSame($before, $this->counts());
        $this->assertSame(1, $run->records_skipped);
        $this->inTenant($this->tenant, function () {
            $item = DB::table('order_items')->where('external_id', '100')->first();
            $this->assertNotNull($item->variant_id);
            $this->assertNotNull($item->product_id);
            $this->assertEquals(1100, (float) $item->row_total);
        });
    }

    public function test_order_status_change_updates_the_row_and_emits_one_event(): void
    {
        $this->shop->members = [FakeTicimax::member(1, 'ayse@example.com')];
        $this->shop->orders = [FakeTicimax::order(10, 1, 'ayse@example.com', [FakeTicimax::orderItem(100, 5, 50)])];
        $this->syncAll();

        $this->shop->orders[0]['Durum'] = 9;
        $this->shop->orders[0]['SiparisDurumu'] = 'İade Edildi';
        $this->shop->orders[0]['DuzenlemeTarihi'] = '2026-10-22T09:00:00';
        $run = $this->sync($this->tenant, $this->integration, SyncEntity::Orders, SyncMode::Delta);
        $this->sync($this->tenant, $this->integration, SyncEntity::Orders, SyncMode::Delta);

        $this->assertSame(1, $run->records_updated);
        $this->inTenant($this->tenant, function () {
            $order = DB::table('orders')->where('external_id', '10')->first();
            $this->assertSame(9, (int) $order->status_code);
            $this->assertFalse((bool) $order->counts_as_revenue);
            $this->assertSame(1, DB::table('orders')->count());
            $this->assertSame(1, DB::table('order_items')->count());
            $events = DB::table('customer_events')->where('name', 'ORDER_STATUS_CHANGED')->get();
            $this->assertCount(1, $events);
            $this->assertFalse((bool) $events[0]->is_backfill);
            $this->assertEqualsCanonicalizing(['order_id', 'from_status', 'to_status', 'from_label', 'to_label', 'counts_as_revenue'], array_keys(json_decode($events[0]->properties, true)));
            $this->assertSame(0, (int) DB::table('customer_stats')->value('order_count'), 'Returned order no longer counts.');
        });
    }

    public function test_cart_resync_detects_content_changes_only(): void
    {
        $this->shop->members = [FakeTicimax::member(1, 'ayse@example.com')];
        $this->shop->carts = [FakeTicimax::cart('g-1', 1, 'ayse@example.com', [FakeTicimax::cartItem('i-1', 5, 50)])];
        $this->syncAll();

        $unchanged = $this->sync($this->tenant, $this->integration, SyncEntity::Carts, SyncMode::Delta);
        $this->assertSame(1, $unchanged->records_skipped);

        $this->shop->carts[0] = FakeTicimax::cart('g-1', 1, 'ayse@example.com', [FakeTicimax::cartItem('i-1', 5, 50, qty: 2), FakeTicimax::cartItem('i-2', 6, 60)]);
        $changed = $this->sync($this->tenant, $this->integration, SyncEntity::Carts, SyncMode::Delta);
        $this->sync($this->tenant, $this->integration, SyncEntity::Carts, SyncMode::Delta);

        $this->assertSame(1, $changed->records_updated);
        $this->inTenant($this->tenant, function () {
            $cart = DB::table('carts')->first();
            $this->assertSame(3, (int) $cart->item_count);
            $this->assertEquals(3300, (float) $cart->total);
            $this->assertNotNull($cart->customer_id);
            $this->assertSame(2, DB::table('cart_items')->count());
            $this->assertSame(2, DB::table('customer_events')->where('name', 'CART_UPDATED')->count());
            $this->assertSame(0, DB::table('customer_events')->where('name', 'like', 'CART_ABANDON%')->count());
            $this->assertNotNull(DB::table('customer_stats')->where('customer_id', $cart->customer_id)->value('last_cart_at'));
        });
    }

    public function test_cart_of_unknown_visitor_never_creates_a_customer(): void
    {
        $this->shop->carts = [FakeTicimax::cart('g-2', 0, 'stranger@example.com', [FakeTicimax::cartItem('i-9', 5, 50)])];

        $this->sync($this->tenant, $this->integration, SyncEntity::Carts);

        $this->inTenant($this->tenant, function () {
            $this->assertSame(1, DB::table('carts')->count());
            $this->assertNull(DB::table('carts')->value('customer_id'));
            $this->assertSame(0, DB::table('customers')->count());
        });
    }

    public function test_order_of_an_unsynced_member_creates_a_stub_that_the_member_sync_completes(): void
    {
        $this->shop->orders = [FakeTicimax::order(40, 77, 'new@example.com', [FakeTicimax::orderItem(400, 5, 50)])];
        $this->sync($this->tenant, $this->integration, SyncEntity::Orders);

        $this->shop->members = [FakeTicimax::member(77, 'new@example.com')];
        $this->sync($this->tenant, $this->integration, SyncEntity::Customers);

        $this->inTenant($this->tenant, function () {
            $this->assertSame(1, DB::table('customers')->count());
            $customer = DB::table('customers')->first();
            $this->assertSame('Ad77', $customer->first_name);
            $this->assertArrayNotHasKey('member_pending', json_decode($customer->attributes, true));
            $this->assertSame($customer->id, DB::table('orders')->value('customer_id'));
        });
    }

    public function test_cursor_advances_only_after_the_whole_window_succeeds(): void
    {
        $this->shop->orders = array_map(fn ($i) => FakeTicimax::order($i, 0, null, [FakeTicimax::orderItem($i * 10, 5, 50)]), range(1, 5));

        // Second page fails permanently.
        $this->shop->failOn('SelectSiparis', new SoapFault('Server', 'boom'), times: 1, after: 1);
        $failed = $this->sync($this->tenant, $this->integration, SyncEntity::Orders);

        $this->assertSame('failed', $failed->status);
        $this->assertSame('permanent', $failed->error_category);
        $this->inTenant($this->tenant, function () {
            $this->assertNull(DB::table('integration_sync_cursors')->where('entity', 'orders')->first());
            $this->assertSame(2, DB::table('orders')->count(), 'Stored pages stay; they are idempotent.');
            $this->assertSame(1, DB::table('integration_errors')->where('category', 'permanent')->count());
            $this->assertSame('error', DB::table('tenant_integrations')->where('id', $this->integration->id)->value('status'));
        });

        $ok = $this->sync($this->tenant, $this->integration, SyncEntity::Orders);

        $this->assertSame('succeeded', $ok->status);
        $this->inTenant($this->tenant, function () use ($ok) {
            $cursor = DB::table('integration_sync_cursors')->where('entity', 'orders')->first();
            $this->assertSame(CarbonImmutable::parse($ok->window_end)->toDateTimeString(), CarbonImmutable::parse($cursor->last_window_end)->toDateTimeString());
            $this->assertNotNull($cursor->last_full_sync_at);
            $this->assertSame(5, DB::table('orders')->count());
            $this->assertSame('connected', DB::table('tenant_integrations')->where('id', $this->integration->id)->value('status'));
        });
    }

    public function test_delta_window_starts_at_cursor_minus_overlap_and_cursor_never_moves_back(): void
    {
        $this->sync($this->tenant, $this->integration, SyncEntity::Orders);
        $first = $this->inTenant($this->tenant, fn () => CarbonImmutable::parse(DB::table('integration_sync_cursors')->where('entity', 'orders')->value('last_window_end')));

        $this->travel(10)->minutes();
        $delta = $this->sync($this->tenant, $this->integration, SyncEntity::Orders, SyncMode::Delta);

        $this->assertSame('delta', $delta->mode);
        $this->assertSame($first->subMinutes(60)->toDateTimeString(), CarbonImmutable::parse($delta->window_start)->toDateTimeString());

        // An older window finishing later must not move the cursor back.
        $this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->where('entity', 'orders')->update(['last_window_end' => now()->addHour()]));
        $this->sync($this->tenant, $this->integration, SyncEntity::Orders, SyncMode::Full);
        $this->inTenant($this->tenant, fn () => $this->assertTrue(CarbonImmutable::parse(DB::table('integration_sync_cursors')->where('entity', 'orders')->value('last_window_end'))->gt(now())));
    }

    public function test_auth_failure_marks_the_integration_action_required(): void
    {
        $this->shop->validKey = 'rotated-by-the-store';
        $this->shop->failOn('SelectUyeler', new SoapFault('Server', 'Yetki kodu hatalı'));

        $run = $this->sync($this->tenant, $this->integration, SyncEntity::Customers);

        $this->assertSame('auth', $run->error_category);
        $this->inTenant($this->tenant, fn () => $this->assertSame('action_required', DB::table('tenant_integrations')->where('id', $this->integration->id)->value('status')));
    }

    public function test_slices_resume_from_the_saved_page_token(): void
    {
        $this->shop->orders = array_map(fn ($i) => FakeTicimax::order($i, 0, null, [FakeTicimax::orderItem($i * 10, 5, 50)]), range(1, 5));

        $first = $this->sync($this->tenant, $this->integration, SyncEntity::Orders, slice: 0);
        $this->assertSame('running', $first->status);
        $this->assertSame('orders:2', $first->page_token);

        $second = $this->sync($this->tenant, $this->integration, SyncEntity::Orders, slice: 0);
        $this->assertSame($first->id, $second->id, 'Continues the same run.');

        $done = $this->sync($this->tenant, $this->integration, SyncEntity::Orders);
        $this->assertSame('succeeded', $done->status);
        $this->assertSame(5, $done->records_read);
        $this->assertSame(3, $this->shop->callsOf('SelectSiparis'), 'No page read twice.');
    }

    public function test_full_sync_events_are_backfill_and_event_ids_are_idempotent(): void
    {
        $this->shop->members = [FakeTicimax::member(1, 'ayse@example.com')];
        $this->shop->orders = [FakeTicimax::order(10, 1, 'ayse@example.com', [FakeTicimax::orderItem(100, 5, 50)])];
        $this->syncAll();
        $this->syncAll();

        $this->inTenant($this->tenant, function () {
            $this->assertSame(0, DB::table('customer_events')->where('is_backfill', false)->count());
            $this->assertSame(1, DB::table('customer_events')->where('name', 'ORDER_PLACED')->count());
            $this->assertSame(1, DB::table('customer_events')->where('name', 'CUSTOMER_CREATED')->count());

            $recorder = app(EventRecorder::class);
            $customer = DB::table('customers')->value('id');
            $this->assertTrue($recorder->record(CustomerEventName::CartUpdated, $customer, now(), 'ticimax', 'cart:x:1', ['cart_id' => 'c', 'item_count' => 1, 'total' => 1]));
            $this->assertFalse($recorder->record(CustomerEventName::CartUpdated, $customer, now(), 'ticimax', 'cart:x:1', ['cart_id' => 'c', 'item_count' => 1, 'total' => 1]));
        });
    }

    public function test_sync_of_one_tenant_never_writes_into_another(): void
    {
        [$other] = $this->createTenant('other');
        $otherShop = new FakeTicimax('other-code');
        $this->seedCatalog($otherShop);
        $otherShop->members = [FakeTicimax::member(1, 'ayse@example.com')];
        $otherIntegration = $this->connectTicimax($other, $otherShop, 'other-code');

        $this->shop->members = [FakeTicimax::member(1, 'ayse@example.com'), FakeTicimax::member(2, 'ali@example.com')];
        $this->syncAll();

        foreach (SyncEntity::ordered() as $entity) {
            $this->sync($other, $otherIntegration, $entity);
        }

        $this->assertSame(2, $this->inTenant($this->tenant, fn () => DB::table('customers')->count()));
        $this->assertSame(1, $this->inTenant($other, fn () => DB::table('customers')->count()));
        $this->assertSame(1, $this->inTenant($other, fn () => DB::table('customer_identities')->where('type', 'commerce_member')->count()));
        $this->assertSame(3, $this->inTenant($other, fn () => DB::table('products')->count()));

        // Running tenant A's integration inside tenant B's context is refused.
        $run = $this->inTenant($other, fn () => rescue(fn () => app(SyncRunner::class)->run($this->integration, SyncEntity::Customers, SyncMode::Full), fn ($e) => $e, false));
        $this->assertInstanceOf(\Throwable::class, $run);
    }

    public function test_tenant_context_survives_a_reconnect_in_the_middle_of_a_sync(): void
    {
        [$other] = $this->createTenant('other');
        $this->createCustomer($other, 'b@other.test');
        $this->shop->members = array_map(fn ($i) => FakeTicimax::member($i, "m{$i}@example.com"), range(1, 5));
        $this->shop->beforeCall = function (string $operation, int $n) {
            if ($operation === 'SelectUyeler' && $n === 2) {
                DB::reconnect('pgsql');
            }
        };

        $run = $this->sync($this->tenant, $this->integration, SyncEntity::Customers);

        $this->assertSame('succeeded', $run->status);
        $this->assertSame(5, $this->inTenant($this->tenant, fn () => DB::table('customers')->count()));
        $this->assertSame(['b@other.test'], $this->inTenant($other, fn () => DB::table('customers')->pluck('email')->all()));
    }
}
