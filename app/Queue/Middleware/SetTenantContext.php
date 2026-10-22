<?php

namespace App\Queue\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

final class SetTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $context = app(TenantContext::class);
        $tenantId = $job->tenantId ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            Log::error('Tenant-aware job dispatched without tenant_id; refusing to run.', ['job' => $job::class]);

            return null;
        }

        return $context->run($tenantId, function () use ($job, $next, $tenantId) {
            Context::add('job_id', method_exists($job, 'job') && $job->job ? $job->job->getJobId() : null);

            $status = Tenant::query()->whereKey($tenantId)->value('status');
            if ($status !== Tenant::STATUS_ACTIVE) {
                Log::warning('Skipping job for inactive or unknown tenant.', ['job' => $job::class]);

                return null;
            }

            return $next($job);
        });
    }
}
