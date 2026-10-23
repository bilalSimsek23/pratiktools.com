<?php

namespace Tests\Feature\Commerce;

use App\Audit\Actor;
use App\Commerce\CommerceSourceFactory;
use App\Commerce\Data\SyncWindow;
use App\Connectors\Ticimax\TicimaxCommerceConnector;
use App\Integrations\Errors\ProviderError;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use LogicException;
use RuntimeException;
use SoapFault;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

final class TicimaxConnectorTest extends TestCase
{
    use InteractsWithTenants, InteractsWithTicimax;

    private Tenant $tenant;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTicimax();
        [$this->tenant] = $this->createTenant('shop');
        $this->integration = $this->connectTicimax($this->tenant);
    }

    private function connector(): TicimaxCommerceConnector
    {
        /** @var TicimaxCommerceConnector */
        return $this->inTenant($this->tenant, fn () => app(CommerceSourceFactory::class)->for($this->integration, 'test', Actor::system()));
    }

    public function test_invalid_credentials_are_reported_as_auth_error(): void
    {
        $this->store($this->tenant)->validKey = 'some-other-code';
        $this->store($this->tenant)->members = [FakeTicimax::member(1, 'a@example.com')];

        $check = $this->connector()->testConnection();

        $this->assertFalse($check->ok);
        $this->assertSame(ProviderError::AUTH, $check->errorCategory);
        $this->assertFalse($check->checks['authorization'] ?? false);
        $this->assertStringNotContainsString('valid-member-code', (string) $check->message);
    }

    public function test_valid_credentials_pass_all_checks(): void
    {
        $store = $this->store($this->tenant);
        $store->members = [FakeTicimax::member(1, 'a@example.com')];
        $store->orders = [FakeTicimax::order(10, 1, 'a@example.com', [FakeTicimax::orderItem(100, 5, 50)])];

        $check = $this->connector()->testConnection();

        $this->assertTrue($check->ok);
        $this->assertSame(['authorization' => true, 'members' => true, 'orders' => true], $check->checks);
    }

    public function test_soap_fault_is_normalized_and_not_retried(): void
    {
        $this->store($this->tenant)->failOn('SelectKategori', new SoapFault('Server', 'Object reference not set to an instance of an object.'));

        try {
            $this->connector()->categories();
            $this->fail('Expected a provider error.');
        } catch (ProviderError $e) {
            $this->assertSame(ProviderError::PERMANENT, $e->category);
            $this->assertSame('SelectKategori', $e->operation);
            $this->assertStringNotContainsString('Object reference', $e->getMessage(), 'Raw fault text must not leak.');
        }

        $this->assertSame(1, $this->store($this->tenant)->callsOf('SelectKategori'));
    }

    public function test_timeout_is_retried_with_backoff_and_then_succeeds(): void
    {
        $store = $this->store($this->tenant);
        $store->categories = [FakeTicimax::category(1, 0, 'Kadın'), FakeTicimax::category(2, 1, 'Elbise')];
        $store->failOn('SelectKategori', new SoapFault('HTTP', 'Error Fetching http headers'), times: 2);

        $page = $this->connector()->categories();

        $this->assertCount(2, $page->records);
        $this->assertSame(3, $store->callsOf('SelectKategori'));
    }

    public function test_timeout_gives_up_after_max_attempts_as_retryable(): void
    {
        $this->store($this->tenant)->failOn('SelectKategori', new SoapFault('HTTP', 'Could not connect to host'), times: 10);

        try {
            $this->connector()->categories();
            $this->fail('Expected a provider error.');
        } catch (ProviderError $e) {
            $this->assertSame(ProviderError::RETRYABLE, $e->category);
            $this->assertTrue($e->isRetryable());
        }

        $this->assertSame(3, $this->store($this->tenant)->callsOf('SelectKategori'));
    }

    public function test_unexpected_response_shape_is_a_schema_mismatch(): void
    {
        $this->store($this->tenant)->members = [['Mail' => 'no-id@example.com']];

        $this->expectExceptionObject(ProviderError::schema('Member without ID.', 'SelectUyeler'));
        $this->connector()->customers(SyncWindow::full(null, CarbonImmutable::now()));
    }

    public function test_single_record_and_list_responses_map_the_same_way(): void
    {
        $store = $this->store($this->tenant);
        $single = FakeTicimax::product(1, [FakeTicimax::variant(11, 'Siyah', 'M')], [3]);
        $store->products = [$single];
        $one = $this->connector()->products(SyncWindow::full(null, CarbonImmutable::now()));

        $store->products = [$single, FakeTicimax::product(2, [FakeTicimax::variant(21, 'Mavi', 'S'), FakeTicimax::variant(22, 'Mavi', 'M')], [3, 4])];
        $two = $this->connector()->products(SyncWindow::full(null, CarbonImmutable::now()));

        $this->assertCount(1, $one->records);
        $this->assertEquals($one->records[0], $two->records[0]);
        $this->assertSame(['3'], $one->records[0]->categoryExternalIds);
        $this->assertSame('Siyah', $one->records[0]->variants[0]->color);
        $this->assertSame('M', $one->records[0]->variants[0]->size);
        $this->assertSame('TRY', $one->records[0]->variants[0]->currency);
        $this->assertCount(2, $two->records[1]->variants);
    }

    public function test_write_operations_are_rejected_before_any_network_call(): void
    {
        $client = $this->connector()->client();

        foreach ([['UyeServis', 'UpdateUyeIzin'], ['UyeServis', 'BulkUpdateUyeIzin'], ['UyeServis', 'HediyeCekiOlustur'], ['UrunServis', 'SaveUrun']] as [$service, $op]) {
            try {
                $client->call($service, $op);
                $this->fail("{$op} must be rejected.");
            } catch (LogicException) {
            }
        }

        $this->assertSame([], $this->store($this->tenant)->calls);
    }

    public function test_member_filter_sends_minus_one_for_flags_ticimax_defaults_to_zero(): void
    {
        $this->store($this->tenant)->members = [FakeTicimax::member(1, 'a@example.com')];
        $this->connector()->customers(SyncWindow::full(null, CarbonImmutable::now()));

        $filter = $this->store($this->tenant)->calls[0]['parameters']['filtre'];
        foreach (['Aktif', 'Cinsiyet', 'MailIzin', 'SmsIzin'] as $flag) {
            $this->assertSame(-1, $filter[$flag], $flag);
        }
    }

    public function test_marketplace_order_is_flagged_by_source_and_channel(): void
    {
        $this->store($this->tenant)->orders = [
            FakeTicimax::order(1, 0, 'x@trendyolmail.com', [FakeTicimax::orderItem(10, 5, 50)], ['SiparisKaynagi' => 'TRENDYOL']),
            FakeTicimax::order(2, 7, 'b@example.com', [FakeTicimax::orderItem(20, 5, 50)], ['SiparisKaynagi' => 'NativeIOS', 'SiparisDurumu' => 'İptal Edildi']),
        ];

        $records = $this->connector()->orders(SyncWindow::full(CarbonImmutable::now()->subYear(), CarbonImmutable::now()))->records;

        $this->assertTrue($records[0]->isMarketplace);
        $this->assertSame('marketplace', $records[0]->channel);
        $this->assertSame('mobile_app', $records[1]->channel);
        $this->assertFalse($records[1]->countsAsRevenue);
        $this->assertSame(1100.0, $records[1]->grandTotal);
        $this->assertSame(1000.0, $records[1]->netTotal);
    }

    public function test_orders_page_by_page_number_and_an_empty_page_fault_ends_the_window(): void
    {
        $store = $this->store($this->tenant);
        $store->orders = array_map(fn ($i) => FakeTicimax::order($i, 0, null, [FakeTicimax::orderItem($i * 10, 5, 50)]), range(1, 4));
        $window = SyncWindow::full(CarbonImmutable::now()->subYear(), CarbonImmutable::now());
        $connector = $this->connector();

        $first = $connector->orders($window);
        $second = $connector->orders($window, $first->nextPageToken);
        $third = $connector->orders($window, $second->nextPageToken);

        $this->assertSame(['1', '2'], array_map(fn ($o) => $o->externalId, $first->records));
        $this->assertSame(['3', '4'], array_map(fn ($o) => $o->externalId, $second->records));
        $this->assertSame([], $third->records);
        $this->assertNull($third->nextPageToken);
        $this->assertSame([0, 1, 2], array_map(fn ($c) => $c['parameters']['s']['BaslangicIndex'], $store->calls));
    }

    public function test_an_order_window_without_orders_is_empty_not_an_error(): void
    {
        $page = $this->connector()->orders(SyncWindow::delta(CarbonImmutable::now()->subHour(), CarbonImmutable::now()));

        $this->assertSame([], $page->records);
        $this->assertNull($page->nextPageToken);
    }

    public function test_factory_refuses_an_integration_of_another_tenant(): void
    {
        [$other] = $this->createTenant('other');

        $this->expectException(RuntimeException::class);
        $this->inTenant($other, fn () => app(CommerceSourceFactory::class)->for($this->integration, 'test'));
    }
}
