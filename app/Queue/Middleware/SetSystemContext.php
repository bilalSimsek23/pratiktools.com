<?php

namespace App\Queue\Middleware;

use App\Tenancy\TenantContext;
use Closure;

final class SetSystemContext
{
    public function handle(object $job, Closure $next): mixed
    {
        return app(TenantContext::class)->runAsSystem(fn () => $next($job));
    }
}
