<?php

namespace Tests\Feature\Commerce;

use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Consent\Sync\ConsentSynchronizer;
use App\Jobs\SyncCommerceEntity;
use App\Jobs\SyncConsent;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\RateLimiting\ProviderRateLimiter;
use App\RateLimiting\TokenBucketLimiter;
use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Predis\Retry\Strategy\ExponentialBackoff;
use SoapFault;
use Tests\Concerns\DrainsSyncQueue;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

/**
 * The production failure of 2026-11-06: products full sync stopped after
 * 13–14 s with Predis ConnectionException from the rate limiter's EVAL
 * (the server had closed the idle connection and Predis never retried).
 * Now Predis reconnects, and a queued run that still hits a transient error
 * stays at its saved page and is retried with bounded backoff.
 */
final class TransientSyncRetryTest extends TestCase
{
    use DrainsSyncQueue, InteractsWithTenants, InteractsWithTicimax;

    private Tenant $tenant;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTicimax();
        [$this->tenant] = $this->createTenant('shop');
        $this->integration = $this->connectTicimax($this->tenant);
        // Five products, two per page: three SelectUrun calls, one EVAL each.
        $this->store($this->tenant)->products = array_map(fn ($i) => FakeTicimax::product($i, [FakeTicimax::variant($i * 10, 'Siyah', 'M')]), range(1, 5));
        $this->freezeSecond();
    }

    /**
     * The rate limiter's Redis: EVAL number $n throws a Predis
     * ConnectionException when $fails($n) says so, like the closed
     * connection did in production.
     */
    private function flakyRedis(Closure $fails): void
    {
        $factory = new class(app('redis'), $fails) implements RedisFactory
        {
            public int $calls = 0;

            public function __construct(private readonly RedisFactory $real, public readonly Closure $fails) {}

            public function connection($name = null)
            {
                $real = $this->real->connection($name);
                $outer = $this;

                return new class($real, $outer)
                {
                    public function __construct(private readonly object $real, private readonly object $outer) {}

                    public function client(): mixed
                    {
                        return $this->real->client();
                    }

                    /**
                     * @param  array<int, mixed>  $parameters
                     */
                    public function command(string $method, array $parameters = []): mixed
                    {
                        $this->outer->calls++;
                        if (($this->outer->fails)($this->outer->calls)) {
                            throw new ConnectionException($this->real->client()->getConnection(), 'Stream is already at the end');
                        }

                        return $this->real->command($method, $parameters);
                    }
                };
            }
        };
        $this->app->instance(TokenBucketLimiter::class, new TokenBucketLimiter($factory));
        $this->app->forgetInstance(ProviderRateLimiter::class);
        $this->setUpTicimax();
    }

    private function dispatchProducts(): void
    {
        SyncCommerceEntity::dispatch($this->integration->id, SyncEntity::Products, SyncMode::Full, $this->tenant->id);
    }

    /**
     * @return list<object>
     */
    private function runs(): array
    {
        return $this->inTenant($this->tenant, fn () => DB::table('integration_sync_runs')->where('entity', 'products')->orderBy('started_at')->get()->all());
    }

    private function delaySeconds(SyncCommerceEntity $job): int
    {
        return (int) round(now()->diffInSeconds($job->delay));
    }

    public function test_predis_retries_and_reconnects_after_the_server_drops_the_connection(): void
    {
        $this->assertSame([ExponentialBackoff::class => [100000, 1000000]], config('database.redis.default.retry'));
        $this->assertSame([ExponentialBackoff::class => [100000, 1000000]], config('database.redis.cache.retry'));
        $connection = Redis::connection();
        $this->assertSame(3, $connection->client()->getConnection()->getParameters()->retry->getRetries());

        // The server closes this client's connection (as an idle timeout,
        // restart or scale-to-zero does); the next EVAL reconnects.
        $limiter = app(ProviderRateLimiter::class);
        $this->assertTrue($limiter->attempt('ticimax', $this->tenant->id)->allowed);
        $id = $connection->command('client', ['id']);
        $killer = new Client(array_intersect_key(config('database.redis.default'), array_flip(['host', 'port', 'password', 'database'])));
        $this->assertSame(1, $killer->executeRaw(['CLIENT', 'KILL', 'ID', (string) $id]));
        $this->assertTrue($limiter->attempt('ticimax', $this->tenant->id)->allowed);
        $this->assertNotSame($id, $connection->command('client', ['id']), 'A new connection.');
    }

    public function test_a_transient_redis_failure_is_retried_from_the_saved_page_without_duplicates(): void
    {
        Queue::fake();
        $this->flakyRedis(fn (int $n) => $n === 2);
        $this->dispatchProducts();

        $ran = $this->drainQueue();

        $this->assertCount(2, $ran, 'The failed slice and one retry.');
        $this->assertSame([0, 1], array_map(fn ($j) => $j->retry, $ran));
        $this->assertSame(5, $this->delaySeconds($ran[1]));
        [$run] = $this->runs();
        $this->assertCount(1, $this->runs(), 'The same run continued.');
        $this->assertSame(['succeeded', 5, 5, 0, 1, null, null], [$run->status, $run->records_read, $run->records_inserted, $run->records_skipped, $run->errors, $run->error_category, $run->error_message]);
        $this->assertSame(3, $this->store($this->tenant)->callsOf('SelectUrun'), 'Page 1 was not read again.');
        $this->inTenant($this->tenant, function () {
            $this->assertSame(5, DB::table('products')->count());
            $this->assertSame(5, DB::table('product_variants')->count());
            $error = DB::table('integration_errors')->sole();
            $this->assertSame(['retryable', 'Temporary connection problem (ConnectionException); retrying.'], [$error->category, $error->message]);
            $this->assertEquals(['entity' => 'products', 'mode' => 'full', 'page_token' => 'all:2', 'deferred' => true], json_decode($error->context, true));
            $this->assertNotNull(DB::table('integration_sync_cursors')->where('entity', 'products')->value('last_full_sync_at'));
            $this->assertSame('connected', DB::table('tenant_integrations')->where('id', $this->integration->id)->value('status'));
        });
    }

    public function test_retries_are_bounded_and_then_the_run_fails_with_a_safe_reason(): void
    {
        Queue::fake();
        $this->flakyRedis(fn () => true);
        $this->dispatchProducts();

        $ran = $this->drainQueue();

        $this->assertSame([0, 1, 2, 3, 4], array_map(fn ($j) => $j->retry, $ran), 'Four retries, then no more jobs.');
        $this->assertSame([5, 15, 45, 120], array_map(fn ($j) => $this->delaySeconds($j), array_slice($ran, 1)));
        [$run] = $this->runs();
        $this->assertSame(['failed', 'retryable', 'Gave up after 4 retries: Temporary connection problem (ConnectionException); retrying.', 0],
            [$run->status, $run->error_category, $run->error_message, $run->records_read]);
        $this->assertSame(0, $this->store($this->tenant)->callsOf('SelectUrun'));
        $this->inTenant($this->tenant, function () {
            $this->assertSame('error', DB::table('tenant_integrations')->where('id', $this->integration->id)->value('status'));
            $this->assertNull(DB::table('integration_sync_cursors')->where('entity', 'products')->first(), 'The cursor did not move.');
        });
    }

    public function test_a_permanent_failure_fails_at_once_and_is_never_retried(): void
    {
        Queue::fake();
        $this->store($this->tenant)->failOn('SelectUrun', new SoapFault('Server', 'Object reference not set to an instance of an object.'), after: 1);
        $this->dispatchProducts();

        $ran = $this->drainQueue();

        $this->assertCount(1, $ran);
        [$run] = $this->runs();
        $this->assertSame(['failed', 'permanent', 'all:2', 2], [$run->status, $run->error_category, $run->page_token, $run->records_read]);
    }

    public function test_starting_a_failed_run_again_resumes_at_its_page_and_creates_no_duplicates(): void
    {
        Queue::fake();
        $this->store($this->tenant)->failOn('SelectUrun', new SoapFault('Server', 'Object reference not set to an instance of an object.'), after: 1);
        $this->dispatchProducts();
        $this->drainQueue();
        [$failed] = $this->runs();

        $this->dispatchProducts();
        $this->drainQueue();

        [, $resumed] = $this->runs();
        $this->assertSame(['succeeded', 'full', 'all:2', 3, 3, 0], [$resumed->status, $resumed->mode, $failed->page_token, $resumed->records_read, $resumed->records_inserted, $resumed->records_skipped]);
        $this->assertSame([$failed->window_start, $failed->window_end], [$resumed->window_start, $resumed->window_end], 'The same window.');
        $this->assertSame(4, $this->store($this->tenant)->callsOf('SelectUrun'), 'Page 1 once, page 2 twice (failed, resumed), page 3 once.');
        $this->assertSame(5, $this->inTenant($this->tenant, fn () => DB::table('products')->count()));

        // An old failure starts over instead.
        $this->store($this->tenant)->failOn('SelectUrun', new SoapFault('Server', 'Object reference not set to an instance of an object.'), after: 1);
        $this->dispatchProducts();
        $this->drainQueue();
        $this->travel(49)->hours();
        $this->dispatchProducts();
        $this->drainQueue();
        $last = $this->runs()[3];
        $this->assertSame(['succeeded', 5, 0, 5], [$last->status, $last->records_read, $last->records_inserted, $last->records_skipped]);
    }

    public function test_a_run_left_running_by_a_restarted_worker_or_a_backlog_continues_at_its_page(): void
    {
        Queue::fake();
        config(['commerce.sync.slice_seconds' => 0]);
        $this->dispatchProducts();
        // One slice (one page) runs; then the worker dies (Horizon restart)
        // and its continuation never runs.
        $first = Queue::pushedJobs()[SyncCommerceEntity::class][0]['job'];
        $this->drained[spl_object_id($first)] = true;
        $this->inTenant($this->tenant, fn () => app()->call([$first, 'handle']));
        foreach (Queue::pushedJobs()[SyncCommerceEntity::class] as $entry) {
            $this->drained[spl_object_id($entry['job'])] = true;
        }
        [$run] = $this->runs();
        $this->assertSame(['running', 'all:2', 2], [$run->status, $run->page_token, $run->records_read]);

        // Much later (after a restart or a long backlog) the sync is queued again.
        $this->travel(3)->hours();
        $this->dispatchProducts();
        $this->drainQueue();

        $this->assertCount(1, $this->runs());
        [$run] = $this->runs();
        $this->assertSame(['succeeded', 5, 5, 0], [$run->status, $run->records_read, $run->records_inserted, $run->records_skipped]);
        $this->assertSame(3, $this->store($this->tenant)->callsOf('SelectUrun'));
        $this->assertSame(5, $this->inTenant($this->tenant, fn () => DB::table('products')->count()));
    }

    public function test_the_ticimax_history_is_retried_the_same_way(): void
    {
        Queue::fake();
        config(['consent.ticimax.history_start' => now()->subMonths(3)->toDateString()]);
        $this->store($this->tenant)->consentHistory = [FakeTicimax::consentChange(1, 1, 2, true, 'ayse@example.com', now()->subMonths(2)->format('Y-m-d\TH:i:s'))];
        $this->flakyRedis(fn (int $n) => $n === 2);
        SyncConsent::dispatch($this->integration->id, 'ticimax_history', $this->tenant->id);

        $ran = $this->drainQueue();

        $this->assertSame([0, 1], array_map(fn ($j) => $j->retry, $ran));
        $run = $this->inTenant($this->tenant, fn () => DB::table('integration_sync_runs')->where('entity', 'ticimax_consent_history')->sole());
        $this->assertSame(['succeeded', 1, null], [$run->status, $run->errors, $run->error_category]);
        $this->assertSame(4, $this->store($this->tenant)->callsOf('SelectUyeIzinGecmisi'), 'Three monthly windows and the current one, the failed one not re-read.');
    }

    public function test_the_consent_build_works_in_resumable_slices_and_matches_the_command(): void
    {
        foreach (['ayse@example.com', 'fatma@example.com', 'zeynep@example.com'] as $email) {
            $this->createCustomer($this->tenant, $email);
        }
        $count = fn () => $this->inTenant($this->tenant, fn () => [DB::table('consent_recipients')->count(), DB::table('recipient_customers')->count()]);

        $runs = $this->inTenant($this->tenant, function () {
            $sync = app(ConsentSynchronizer::class);
            $integration = TenantIntegration::query()->findOrFail($this->integration->id);
            $statuses = [];
            do {
                $run = $sync->buildRun($integration, sliceSeconds: 0, chunk: 1);
                $statuses[] = $run->status;
            } while ($run->status === 'running' && count($statuses) < 20);

            return $statuses;
        });

        // One customer per slice, the empty last chunk, then one slice per
        // store-consent snapshot (the last one finishes the run).
        $this->assertSame(['running', 'running', 'running', 'running', 'running', 'running', 'succeeded'], $runs);
        $built = $count();
        $this->assertSame([3, 3], $built);
        $run = $this->inTenant($this->tenant, fn () => DB::table('integration_sync_runs')->where('entity', 'consent_build')->sole());
        $this->assertSame(['full', 3, 'snapshot:3'], [$run->mode, $run->records_read, $run->page_token]);
        $this->assertNotNull($this->inTenant($this->tenant, fn () => DB::table('integration_sync_cursors')->where('entity', 'consent_build')->value('last_full_sync_at')));

        // The command (consent:build --skip-history) builds the same, idempotently.
        $this->inTenant($this->tenant, fn () => app(ConsentSynchronizer::class)->buildFromCustomers());
        $this->assertSame($built, $count());
    }

    public function test_an_inline_command_run_is_not_deferred(): void
    {
        $this->flakyRedis(fn (int $n) => $n === 2);
        $run = $this->sync($this->tenant, $this->integration, SyncEntity::Products);

        $this->assertSame(['failed', 'permanent', 'Unexpected ConnectionException while storing synced data.'], [$run->status, $run->error_category, $run->error_message]);
    }
}
