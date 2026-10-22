<?php

namespace App\Queue;

use App\Queue\Middleware\SetTenantContext;
use App\Tenancy\TenantContext;

/**
 * For queued jobs that work on one tenant's data. The tenant id is captured
 * when the job is created (from the explicit argument or the current
 * context) and travels in the payload; the job middleware restores exactly
 * that tenant while the job runs and the previous context afterwards.
 *
 * Usage: call $this->forTenant() in the constructor.
 */
trait TenantAwareJob
{
    public string $tenantId;

    protected function forTenant(?string $tenantId = null): void
    {
        $this->tenantId = $tenantId ?? app(TenantContext::class)->requireTenant();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }
}
