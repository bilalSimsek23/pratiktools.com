<?php

namespace App\Console\Commands\Concerns;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantDirectory;
use Closure;
use RuntimeException;

/**
 * Console commands that touch tenant data must name the tenant explicitly
 * (--tenant=<slug>). There is no implicit "all tenants" mode.
 */
trait RunsInTenantContext
{
    /**
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    protected function inTenant(Closure $callback): mixed
    {
        $slug = (string) $this->option('tenant');
        $tenantId = $slug === '' ? null : app(TenantDirectory::class)->idBySlug($slug);

        if ($tenantId === null) {
            throw new RuntimeException('Unknown or inactive tenant. Pass --tenant=<slug>.');
        }

        return app(TenantContext::class)->run($tenantId, fn () => $callback($tenantId));
    }
}
