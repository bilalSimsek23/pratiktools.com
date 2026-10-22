<?php

namespace App\Queue;

use App\Queue\Middleware\SetSystemContext;

/**
 * For platform-level jobs that belong to no tenant (heartbeats, platform
 * maintenance). They run in the "system" context, which grants access to
 * tenant-less rows only and never to any tenant's rows.
 */
trait PlatformJob
{
    public ?string $tenantId = null;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetSystemContext];
    }
}
