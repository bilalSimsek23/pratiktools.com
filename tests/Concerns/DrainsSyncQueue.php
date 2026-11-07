<?php

namespace Tests\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Works off a Queue::fake() like a worker would: every pushed job, in push
 * order, inside its tenant's context, with its unique lock released when
 * processing starts (ShouldBeUniqueUntilProcessing) and failed() called on
 * an exception. Jobs a job pushes are worked off too, until none is left.
 */
trait DrainsSyncQueue
{
    /** @var array<int, true> */
    private array $drained = [];

    /**
     * @return list<object> the jobs that ran, in order
     */
    protected function drainQueue(int $limit = 500): array
    {
        $ran = [];
        while (true) {
            $next = null;
            foreach (Queue::pushedJobs() as $pushed) {
                foreach ($pushed as $entry) {
                    if (! isset($this->drained[spl_object_id($entry['job'])])) {
                        $next ??= $entry['job'];
                    }
                }
            }
            if ($next === null) {
                return $ran;
            }
            $this->assertLessThan($limit, count($ran), 'The queue never ran empty (endless re-queueing?).');
            $this->drained[spl_object_id($next)] = true;
            $ran[] = $next;
            (new UniqueLock(app(Cache::class)))->release($next);
            try {
                app(TenantContext::class)->run($next->tenantId, fn () => app()->call([$next, 'handle']));
            } catch (Throwable $e) {
                method_exists($next, 'failed') ? $next->failed($e) : throw $e;
            }
        }
    }
}
