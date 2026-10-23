<?php

namespace Tests\Feature\Infra;

use App\Commerce\Sync\SyncEntity;
use App\Commerce\Sync\SyncMode;
use App\Jobs\RebuildCustomerStats;
use App\Jobs\SyncCommerceEntity;
use App\Jobs\SyncConsent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithTicimax;
use Tests\Support\FakeTicimax;
use Tests\TestCase;

/**
 * A job still running when retry_after elapses is handed to a second
 * worker. Every timeout must stay below the queue's retry_after.
 */
final class QueueTimeoutTest extends TestCase
{
    use InteractsWithTenants, InteractsWithTicimax;

    private const MARGIN_SECONDS = 30;

    /**
     * @return list<class-string<ShouldQueue>>
     */
    private function jobs(): array
    {
        $classes = [];
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            if (is_subclass_of($class, ShouldQueue::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function test_every_job_and_supervisor_timeout_is_below_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $supervisorTimeouts = [];
        foreach (array_merge([config('horizon.defaults')], array_values(config('horizon.environments'))) as $supervisors) {
            foreach ($supervisors as $name => $s) {
                if (isset($s['timeout'])) {
                    $supervisorTimeouts[$name] = $s['timeout'];
                }
            }
        }

        $this->assertNotEmpty($supervisorTimeouts);
        foreach ($supervisorTimeouts as $name => $timeout) {
            $this->assertLessThan($retryAfter - self::MARGIN_SECONDS, $timeout, "Horizon {$name} timeout");
        }

        $fallback = max($supervisorTimeouts);
        $this->assertNotEmpty($this->jobs());
        foreach ($this->jobs() as $job) {
            $timeout = (new ReflectionClass($job))->getDefaultProperties()['timeout'] ?? $fallback;
            $this->assertLessThan($retryAfter - self::MARGIN_SECONDS, $timeout, "{$job} timeout must be < retry_after ({$retryAfter})");
        }
    }

    public function test_sync_jobs_hold_an_overlap_lock_longer_than_they_can_run(): void
    {
        foreach ([
            new SyncCommerceEntity('i1', SyncEntity::Orders, SyncMode::Delta, 't1'),
            new SyncConsent('i1', 'iys_status', 't1'),
            new RebuildCustomerStats('t1'),
        ] as $job) {
            $lock = collect($job->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);
            $this->assertNotNull($lock, $job::class);
            $this->assertGreaterThan($job->timeout, $lock->expiresAfter, $job::class);
            $this->assertNull($lock->releaseAfter, $job::class.' drops duplicates instead of re-queuing them.');
        }
    }

    public function test_a_duplicate_delivery_never_calls_the_provider_twice(): void
    {
        $this->setUpTicimax();
        [$tenant] = $this->createTenant('shop');
        $integration = $this->connectTicimax($tenant);
        $this->store($tenant)->orders = [FakeTicimax::order(1, 0, null, [FakeTicimax::orderItem(10, 5, 50)])];
        $job = new SyncCommerceEntity($integration->id, SyncEntity::Orders, SyncMode::Full, $tenant->id);

        // The first delivery is still running (it holds the overlap lock).
        $lock = Cache::lock('laravel-queue-overlap:'.SyncCommerceEntity::class.':commerce:'.$job->uniqueId(), 600);
        $this->assertTrue($lock->get());

        dispatch_sync($job);
        $this->assertSame(0, $this->store($tenant)->callsOf('SelectSiparis'), 'Duplicate dropped before any API call.');

        $lock->release();
        dispatch_sync(new SyncCommerceEntity($integration->id, SyncEntity::Orders, SyncMode::Full, $tenant->id));
        $this->assertGreaterThan(0, $this->store($tenant)->callsOf('SelectSiparis'));
    }
}
