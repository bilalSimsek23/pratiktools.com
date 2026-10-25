<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Commerce\Sync\CommerceRelink;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

/**
 * Local-only repair of cart/order item catalog links and cart customers
 * (no Ticimax call). --dry-run counts inside a READ ONLY transaction.
 */
final class CommerceCartRelink extends Command
{
    use RunsInTenantContext;

    protected $signature = 'commerce:cart-relink {--tenant=} {--dry-run} {--chunk=1000}';

    protected $description = 'Relink cart/order items to the catalog and carts to customers (local database only)';

    public function handle(CommerceRelink $relink, AuditLogger $audit): int
    {
        return $this->inTenant(function (string $tenantId) use ($relink, $audit) {
            if ($this->option('dry-run')) {
                $this->line(json_encode(['dry_run' => true] + $relink->dryRun(), JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            $started = microtime(true);
            $result = $relink->run(max(100, (int) $this->option('chunk')));
            $audit->record(AuditAction::CommerceRelinked, 'tenant', $tenantId, $result['updated'], Actor::console($this->getName()));
            $this->line(json_encode($result + ['duration_ms' => (int) ((microtime(true) - $started) * 1000)], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        });
    }
}
