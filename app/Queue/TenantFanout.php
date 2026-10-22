<?php

namespace App\Queue;

use App\Tenancy\TenantDirectory;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Scheduler helper: dispatch one tenant-bound job per active tenant. The
 * scheduler itself never enters a tenant context; each job carries its
 * tenant id and gets its context from the job middleware.
 */
final class TenantFanout
{
    public function __construct(private readonly TenantDirectory $directory) {}

    /**
     * @param  Closure(string): ShouldQueue  $makeJob
     */
    public function dispatch(Closure $makeJob): int
    {
        $count = 0;

        foreach ($this->directory->activeTenantIds() as $tenantId) {
            dispatch($makeJob($tenantId));
            $count++;
        }

        return $count;
    }
}
