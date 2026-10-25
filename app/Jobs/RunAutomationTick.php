<?php

namespace App\Jobs;

use App\Automation\AutomationEngine;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One tenant's automation tick on the "automation" queue (never "send").
 * Unique per tenant while queued and never overlapping while running; the
 * work itself is bounded (batch and time budget), and claims are atomic,
 * so even a duplicate execution cannot process a run twice.
 */
final class RunAutomationTick implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('automation');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext, (new WithoutOverlapping("automation-tick:{$this->tenantId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(AutomationEngine $engine): void
    {
        $engine->tick();
    }
}
