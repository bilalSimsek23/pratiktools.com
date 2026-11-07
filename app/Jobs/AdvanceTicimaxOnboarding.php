<?php

namespace App\Jobs;

use App\Commerce\Onboarding\TicimaxOnboarding;
use App\Models\TenantIntegration;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Moves a tenant's Ticimax onboarding one step: queues the first
 * unfinished step (see TicimaxOnboarding::advance). Short-lived; the long
 * work happens in the step's own job, which queues this one again when it
 * succeeded. The browser can be closed at any time.
 */
final class AdvanceTicimaxOnboarding implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    /**
     * The unique lock expires, so a job lost from the queue (flushed Redis)
     * never blocks starting the same sync again. Overlap is still prevented
     * by the run itself (one running run per entity) and its lock.
     */
    public int $uniqueFor = 3600;

    /**
     * The connection test (three read calls with the client's own retries)
     * is the longest thing this job does.
     */
    public int $timeout = 600;

    public function __construct(public readonly string $integrationId, ?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function handle(TicimaxOnboarding $onboarding): void
    {
        $integration = TenantIntegration::query()->where('provider', 'ticimax')->where('capability', 'commerce')->find($this->integrationId);
        if ($integration !== null) {
            $onboarding->advance($integration);
        }
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // No overlap lock that drops a second move: advancing is idempotent
        // (a step already queued or running is not queued again, and the
        // step jobs are unique), and a dropped move would stall the chain.
        return [new SetTenantContext];
    }
}
