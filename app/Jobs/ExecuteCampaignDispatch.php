<?php

namespace App\Jobs;

use App\Dispatch\CampaignDispatches;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Executes one approved test-only dispatch on the send queue. tries = 1:
 * a failed dispatch is retried explicitly by an operator (same request,
 * same per-recipient keys).
 */
final class ExecuteCampaignDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $dispatchId, ?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('send');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext, (new WithoutOverlapping("campaign-dispatch:{$this->dispatchId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(CampaignDispatches $dispatches): void
    {
        $dispatches->execute($this->dispatchId);
    }
}
