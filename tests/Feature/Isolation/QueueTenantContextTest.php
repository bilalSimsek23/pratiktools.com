<?php

namespace Tests\Feature\Isolation;

use App\Jobs\RecordTenantIntegrationHealth;
use App\Models\Customer;
use App\Queue\TenantFanout;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\Fixtures\Jobs\ProbeTenantJob;
use Tests\Fixtures\Jobs\ProbeWithoutTenantJob;
use Tests\TestCase;

#[Group('tenant-isolation')]
final class QueueTenantContextTest extends TestCase
{
    use InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        ProbeTenantJob::$results = [];
        ProbeWithoutTenantJob::$results = [];
    }

    public function test_job_for_tenant_a_cannot_read_tenant_b_data(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $theirs = $this->createCustomer($b, 'b1@beta.test');
        $this->createCustomer($a, 'a1@alpha.test');

        dispatch_sync(new ProbeTenantJob($a->id, $theirs->id));

        $result = ProbeTenantJob::$results[0];
        $this->assertSame($a->id, $result['context']);
        $this->assertFalse($result['found_target']);
        $this->assertSame(0, $result['raw_target_rows']);
        $this->assertSame(['a1@alpha.test'], $result['visible_emails']);
    }

    public function test_job_payload_carries_tenant_id_captured_at_dispatch(): void
    {
        [$a] = $this->createTenant('alpha');

        $job = $this->inTenant($a, fn () => new RecordTenantIntegrationHealth);

        $this->assertSame($a->id, $job->tenantId);
        $this->assertStringContainsString($a->id, serialize($job));
    }

    public function test_tenant_job_cannot_be_created_without_context(): void
    {
        $this->expectException(TenantContextMissing::class);

        new RecordTenantIntegrationHealth;
    }

    public function test_job_without_tenant_middleware_has_no_tenant_access_even_inside_a_tenant_request(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createCustomer($a, 'a1@alpha.test');

        // Dispatched synchronously while a tenant context is active: the queue
        // guard must not let the job inherit it.
        $this->inTenant($a, fn () => dispatch_sync(new ProbeWithoutTenantJob));

        $result = ProbeWithoutTenantJob::$results[0];
        $this->assertNull($result['context']);
        $this->assertSame(0, $result['raw_rows']);
        $this->assertSame('missing', $result['eloquent']);
    }

    public function test_context_is_restored_after_a_sync_job(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');

        $this->inTenant($a, function () use ($b, $a) {
            dispatch_sync(new ProbeTenantJob($b->id, null));
            $this->assertSame($a->id, app(TenantContext::class)->tenantId());
        });

        $this->assertSame($b->id, ProbeTenantJob::$results[0]['context']);
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }

    #[Group('redis')]
    public function test_real_worker_runs_consecutive_jobs_for_different_tenants_without_leaking(): void
    {
        config(['queue.default' => 'redis']);
        Redis::connection()->flushdb();

        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $this->createCustomer($a, 'a1@alpha.test');
        $theirs = $this->createCustomer($b, 'b1@beta.test');

        ProbeTenantJob::dispatch($a->id, $theirs->id)->onConnection('redis')->onQueue('isolation-test');
        ProbeWithoutTenantJob::dispatch()->onConnection('redis')->onQueue('isolation-test');
        ProbeTenantJob::dispatch($b->id, $theirs->id)->onConnection('redis')->onQueue('isolation-test');

        for ($i = 0; $i < 3; $i++) {
            Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'isolation-test', '--once' => true, '--stop-when-empty' => true]);
        }

        $this->assertCount(2, ProbeTenantJob::$results);
        [$first, $third] = ProbeTenantJob::$results;

        $this->assertSame($a->id, $first['context']);
        $this->assertFalse($first['found_target']);
        $this->assertSame(['a1@alpha.test'], $first['visible_emails']);

        $this->assertNull(ProbeWithoutTenantJob::$results[0]['context']);
        $this->assertSame(0, ProbeWithoutTenantJob::$results[0]['raw_rows']);

        $this->assertSame($b->id, $third['context']);
        $this->assertTrue($third['found_target']);
        $this->assertSame(['b1@beta.test'], $third['visible_emails']);

        $this->assertFalse(app(TenantContext::class)->hasTenant());
        $this->assertSame('', (string) DB::selectOne("SELECT current_setting('app.tenant_id', true) AS t")->t);
    }

    public function test_scheduler_fanout_dispatches_one_job_per_active_tenant_with_its_tenant_id(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        [$c] = $this->createTenant('gamma');
        $this->inTenant($c, fn () => $c->forceFill(['status' => 'suspended'])->save());

        Queue::fake();

        $count = app(TenantFanout::class)->dispatch(fn (string $tenantId) => new RecordTenantIntegrationHealth($tenantId));

        $this->assertSame(2, $count);
        Queue::assertPushed(RecordTenantIntegrationHealth::class, 2);
        $pushed = Queue::pushed(RecordTenantIntegrationHealth::class)->map(fn ($job) => $job->tenantId)->sort()->values()->all();
        $this->assertSame(collect([$a->id, $b->id])->sort()->values()->all(), $pushed);
        $this->assertFalse(app(TenantContext::class)->hasTenant(), 'The scheduler itself must not hold a tenant context.');
    }

    public function test_job_for_suspended_tenant_does_not_run(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->inTenant($a, fn () => $a->forceFill(['status' => 'suspended'])->save());

        dispatch_sync(new ProbeTenantJob($a->id, null));

        $this->assertSame([], ProbeTenantJob::$results);
    }

    public function test_console_command_requires_an_explicit_tenant(): void
    {
        [$a] = $this->createTenant('alpha');
        $this->createIntegration($a);

        $this->artisan('vault:rotate-data-key', ['--tenant' => 'alpha'])->assertSuccessful();
        $this->assertFalse(app(TenantContext::class)->hasTenant());

        $this->expectException(\RuntimeException::class);
        $this->artisan('vault:rotate-data-key')->run();
    }

    public function test_eloquent_models_are_unreachable_after_any_job_finishes(): void
    {
        [$a] = $this->createTenant('alpha');
        dispatch_sync(new ProbeTenantJob($a->id, null));

        $this->expectException(TenantContextMissing::class);
        Customer::query()->count();
    }
}
