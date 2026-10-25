<?php

namespace App\Jobs;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Commerce\Sync\CommerceRelink;
use App\Queue\Middleware\SetTenantContext;
use App\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Nightly local repair of item catalog links and cart customers (part of
 * the commerce reconciliation). Database only; nothing goes to Ticimax.
 * Idempotent: a row already linked is never touched.
 */
final class RelinkCommerceData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(?string $tenantId = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('sync');
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
        return [new SetTenantContext, (new WithoutOverlapping("commerce-relink:{$this->tenantId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(CommerceRelink $relink, AuditLogger $audit): void
    {
        $result = $relink->run();
        if (array_sum($result['updated']) > 0) {
            $audit->record(AuditAction::CommerceRelinked, 'tenant', $this->tenantId, $result['updated'], Actor::job(self::class));
        }
    }
}
