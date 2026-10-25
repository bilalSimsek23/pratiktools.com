<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Jobs\RebuildCustomerStats;
use App\Jobs\RelinkCommerceData;
use App\Jobs\ScheduleCommerceSync;
use App\Jobs\SyncCommerceEntity;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Tenancy\TenantRole;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

final class CommerceApiAndJobsTest extends TestCase
{
    use InteractsWithTenants, InteractsWithTicimax;

    private Tenant $tenant;

    private User $owner;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTicimax();
        [$this->tenant, $this->owner] = $this->createTenant('shop');
        $this->integration = $this->connectTicimax($this->tenant);
        $shop = $this->store($this->tenant);
        $shop->members = [FakeTicimax::member(1, 'ayse.yilmaz@example.com')];
        $shop->orders = [FakeTicimax::order(10, 1, 'ayse.yilmaz@example.com', [FakeTicimax::orderItem(100, 5, 50)])];
        $shop->products = [FakeTicimax::product(5, [FakeTicimax::variant(50, 'Siyah', 'M')])];
    }

    public function test_connection_test_endpoint_updates_status(): void
    {
        $token = $this->tokenFor($this->owner, $this->tenant);

        $this->api('POST', "/api/v1/integrations/{$this->integration->id}/test", $token)
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'connected', 'checks' => ['authorization' => true]]);

        $this->store($this->tenant)->validKey = 'changed';
        $this->api('POST', "/api/v1/integrations/{$this->integration->id}/test", $token)
            ->assertUnprocessable()
            ->assertJson(['ok' => false, 'status' => 'action_required', 'error_category' => 'auth']);
    }

    public function test_sync_status_and_preview_mask_contact_data(): void
    {
        foreach (SyncEntity::ordered() as $entity) {
            $this->sync($this->tenant, $this->integration, $entity);
        }
        $token = $this->tokenFor($this->owner, $this->tenant);

        $status = $this->api('GET', "/api/v1/integrations/{$this->integration->id}/sync-status", $token)->assertOk();
        $status->assertJsonPath('data.status', 'CONNECTED')
            ->assertJsonPath('data.entities.orders.last_run.status', 'succeeded')
            ->assertJsonPath('data.entities.orders.last_run.records_read', 1);
        $this->assertNotNull($status->json('data.entities.customers.cursor'));

        $preview = $this->api('GET', "/api/v1/integrations/{$this->integration->id}/preview", $token)->assertOk();
        $body = $preview->getContent();
        $this->assertStringNotContainsString('ayse.yilmaz@example.com', $body);
        $this->assertStringNotContainsString('5321112201', $body);
        $preview->assertJsonPath('data.customers.0.email', 'a***@example.com');
        $this->assertLessThanOrEqual(10, count($preview->json('data.orders')));
    }

    public function test_sync_endpoint_queues_jobs_on_the_sync_queue(): void
    {
        Queue::fake();
        $token = $this->tokenFor($this->owner, $this->tenant);

        $this->api('POST', "/api/v1/integrations/{$this->integration->id}/sync", $token, ['entities' => ['orders', 'carts'], 'mode' => 'delta'])
            ->assertStatus(202);

        Queue::assertPushedOn('sync', SyncCommerceEntity::class);
        Queue::assertPushed(SyncCommerceEntity::class, 2);
        Queue::assertPushed(SyncCommerceEntity::class, fn (SyncCommerceEntity $job) => $job->tenantId === $this->tenant->id);
    }

    public function test_sync_endpoint_refuses_an_integration_that_needs_action(): void
    {
        Queue::fake();
        $this->inTenant($this->tenant, fn () => $this->integration->forceFill(['status' => 'action_required'])->save());

        $this->api('POST', "/api/v1/integrations/{$this->integration->id}/sync", $this->tokenFor($this->owner, $this->tenant))->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_viewer_cannot_trigger_sync_and_other_tenants_cannot_see_the_integration(): void
    {
        $viewer = $this->addMember($this->tenant, TenantRole::Viewer);
        $this->api('POST', "/api/v1/integrations/{$this->integration->id}/sync", $this->tokenFor($viewer, $this->tenant))->assertForbidden();

        [$other, $otherOwner] = $this->createTenant('other');
        $token = $this->tokenFor($otherOwner, $other);
        foreach (['sync-status', 'preview'] as $path) {
            $this->api('GET', "/api/v1/integrations/{$this->integration->id}/{$path}", $token)->assertNotFound();
        }
        $this->api('POST', "/api/v1/integrations/{$this->integration->id}/test", $token)->assertNotFound();
    }

    public function test_ticimax_integration_requires_safe_https_settings(): void
    {
        [$fresh, $owner] = $this->createTenant('fresh');
        $token = $this->tokenFor($owner, $fresh);
        $payload = ['provider' => 'ticimax', 'capability' => 'commerce', 'secret' => 'member-code-123'];

        $this->api('POST', '/api/v1/integrations', $token, $payload)->assertUnprocessable()->assertJsonValidationErrors('settings.service_url');
        $this->api('POST', '/api/v1/integrations', $token, $payload + ['settings' => ['service_url' => 'http://shop.example.com/Servis/', 'storefront_url' => 'https://shop.example.com']])
            ->assertUnprocessable();

        $created = $this->api('POST', '/api/v1/integrations', $token, $payload + ['settings' => ['service_url' => 'https://shop.example.com/Servis', 'storefront_url' => 'https://shop.example.com/']])
            ->assertCreated();
        $created->assertJsonPath('data.settings.service_url', 'https://shop.example.com/Servis/');
        $this->assertStringNotContainsString('member-code-123', $created->getContent());
    }

    public function test_job_continues_itself_until_the_window_is_complete(): void
    {
        config(['commerce.sync.slice_seconds' => 0]);
        $this->store($this->tenant)->orders = array_map(fn ($i) => FakeTicimax::order($i, 0, null, [FakeTicimax::orderItem($i * 10, 5, 50)]), range(1, 5));

        // Run each queued continuation (as the worker would, in its tenant)
        // until none is left.
        Queue::fake([SyncCommerceEntity::class]);
        $job = new SyncCommerceEntity($this->integration->id, SyncEntity::Orders, SyncMode::Full, $this->tenant->id);
        $slices = 0;
        while ($job !== null) {
            // A worker releases the unique lock when it starts processing.
            (new UniqueLock(app('cache')->driver()))->release($job);
            $this->inTenant($this->tenant, fn () => app()->call([$job, 'handle']));
            $slices++;
            $job = Queue::pushed(SyncCommerceEntity::class)->get($slices - 1);
            $this->assertLessThan(10, $slices);
        }

        $this->assertSame(3, $slices);
        $this->inTenant($this->tenant, function () {
            $this->assertSame(5, DB::table('orders')->count());
            $this->assertSame('succeeded', DB::table('integration_sync_runs')->where('entity', 'orders')->value('status'));
        });
    }

    public function test_failed_job_marks_the_run_failed_inside_its_tenant(): void
    {
        $this->store($this->tenant)->orders = array_map(fn ($i) => FakeTicimax::order($i, 0, null, [FakeTicimax::orderItem($i * 10, 5, 50)]), range(1, 5));
        $this->sync($this->tenant, $this->integration, SyncEntity::Orders, slice: 0);

        (new SyncCommerceEntity($this->integration->id, SyncEntity::Orders, SyncMode::Full, $this->tenant->id))->failed(new \RuntimeException('worker died'));

        $this->inTenant($this->tenant, function () {
            $this->assertSame('failed', DB::table('integration_sync_runs')->where('entity', 'orders')->value('status'));
            $this->assertNull(DB::table('integration_sync_cursors')->where('entity', 'orders')->first());
        });
    }

    public function test_schedule_is_disabled_by_default_and_fans_out_per_tenant_when_enabled(): void
    {
        $events = collect(app(Schedule::class)->events())->map->description->filter()->values()->all();
        $this->assertNotContains('commerce-sync-orders', $events);

        Queue::fake([SyncCommerceEntity::class, RebuildCustomerStats::class, RelinkCommerceData::class]);
        dispatch_sync(new ScheduleCommerceSync(['orders' => SyncMode::Delta, 'products' => SyncMode::Full], ['orders' => 7], rebuildStats: true, tenantId: $this->tenant->id));

        Queue::assertPushed(SyncCommerceEntity::class, fn ($job) => $job->entity === SyncEntity::Orders && $job->lookbackDays === 7 && $job->tenantId === $this->tenant->id);
        Queue::assertPushed(SyncCommerceEntity::class, fn ($job) => $job->entity === SyncEntity::Products && $job->mode === SyncMode::Full);
        Queue::assertPushed(RebuildCustomerStats::class);
        Queue::assertPushed(RelinkCommerceData::class);
    }

    public function test_commands_connect_test_sync_and_report_without_printing_secrets(): void
    {
        [$igs] = $this->createTenant('igs');
        $this->fakeStores['https://igs.example.com/Servis/'] = $shop = new FakeTicimax('stdin-secret-code');
        $shop->members = [FakeTicimax::member(1, 'secret.person@example.com')];
        $shop->orders = [FakeTicimax::order(1, 1, 'secret.person@example.com', [FakeTicimax::orderItem(1, 5, 50)])];

        $connect = $this->withoutMockingConsoleOutput();
        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, "stdin-secret-code\n");
        rewind($stdin);
        $this->assertSame(0, $this->runWithStdin($stdin, 'ticimax:connect', ['--tenant' => 'igs', '--service-url' => 'https://igs.example.com/Servis', '--storefront-url' => 'https://igs.example.com', '--secret-stdin' => true]));
        $this->assertStringNotContainsString('stdin-secret-code', Artisan::output());

        $this->assertSame(0, Artisan::call('commerce:test', ['--tenant' => 'igs']));
        $this->assertSame(0, Artisan::call('commerce:sync', ['--tenant' => 'igs', '--entity' => 'customers', '--mode' => 'full', '--inline' => true]));
        $this->assertStringContainsString('succeeded', Artisan::output());
        $this->assertSame(0, Artisan::call('commerce:preview', ['--tenant' => 'igs']));
        $this->assertStringNotContainsString('secret.person@example.com', Artisan::output());
        $this->assertSame(0, Artisan::call('commerce:status', ['--tenant' => 'igs']));
        $this->assertSame(0, Artisan::call('commerce:compare', ['--tenant' => 'igs', '--sample' => 1]));
        $output = Artisan::output();
        $this->assertStringContainsString('ok (', $output);
        $this->assertStringNotContainsString('secret.person', $output);
    }

    /**
     * @param  resource  $stdin
     * @param  array<string, mixed>  $parameters
     */
    private function runWithStdin($stdin, string $command, array $parameters): int
    {
        $input = new ArrayInput(['command' => $command] + $parameters);
        $input->setStream($stdin);

        return Artisan::handle($input, new BufferedOutput);
    }
}
