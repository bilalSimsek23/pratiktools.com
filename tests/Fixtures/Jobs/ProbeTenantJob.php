<?php

namespace Tests\Fixtures\Jobs;

use App\Models\Customer;
use App\Queue\TenantAwareJob;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Records what a tenant-bound job can see. Test fixture only.
 */
final class ProbeTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    /** @var list<array<string, mixed>> */
    public static array $results = [];

    public function __construct(string $tenantId, public readonly ?string $targetCustomerId)
    {
        $this->forTenant($tenantId);
    }

    public function handle(TenantContext $context): void
    {
        self::$results[] = [
            'context' => $context->tenantId(),
            'found_target' => $this->targetCustomerId !== null && Customer::query()->whereKey($this->targetCustomerId)->exists(),
            'raw_target_rows' => $this->targetCustomerId === null ? 0 : count(DB::select('SELECT id FROM customers WHERE id = ?', [$this->targetCustomerId])),
            'visible_emails' => Customer::query()->orderBy('email')->pluck('email')->all(),
        ];
    }
}
