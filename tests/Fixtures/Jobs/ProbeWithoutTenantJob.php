<?php

namespace Tests\Fixtures\Jobs;

use App\Models\Customer;
use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * A job that (wrongly) forgets the tenant middleware. Test fixture only.
 */
final class ProbeWithoutTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array<string, mixed>> */
    public static array $results = [];

    public function handle(TenantContext $context): void
    {
        try {
            Customer::query()->count();
            $eloquent = 'allowed';
        } catch (TenantContextMissing) {
            $eloquent = 'missing';
        }

        self::$results[] = [
            'context' => $context->tenantId(),
            'raw_rows' => (int) DB::selectOne('SELECT count(*) AS n FROM customers')->n,
            'eloquent' => $eloquent,
        ];
    }
}
