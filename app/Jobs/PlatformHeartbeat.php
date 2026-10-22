<?php

namespace App\Jobs;

use App\Queue\PlatformJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Proves end to end that the scheduler and the "system" queue run.
 * Read by the health check.
 */
final class PlatformHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PlatformJob, Queueable;

    public const CACHE_KEY = 'platform:heartbeat';

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addMinutes(10));
    }
}
