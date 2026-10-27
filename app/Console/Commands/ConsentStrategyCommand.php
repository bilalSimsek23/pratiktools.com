<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Consent\ConsentStrategy;
use App\Consent\ConsentStrategyMode;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

/**
 * Shows or changes a tenant's consent strategy. Changing it is an explicit,
 * audited operator decision with a reason; nothing switches it
 * automatically. Switching never enables sending: eligibility stays
 * fail-closed until the chosen strategy is operational.
 */
final class ConsentStrategyCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:strategy {--tenant= : Tenant slug} {--set= : direct_api|via_reconciliation} {--reason= : Why (required with --set)}';

    protected $description = 'Show or change the consent strategy of a tenant (İYS API or ViA + daily reconciliation)';

    public function handle(ConsentStrategy $strategy): int
    {
        return $this->inTenant(function () use ($strategy): int {
            if ($this->option('set') !== null) {
                $mode = ConsentStrategyMode::tryFrom((string) $this->option('set'));
                if ($mode === null || trim((string) $this->option('reason')) === '') {
                    $this->error('Pass --set=direct_api|via_reconciliation and --reason.');

                    return self::FAILURE;
                }
                $strategy->set($mode, (string) $this->option('reason'), Actor::console($this->getName()));
                $this->info("Consent strategy set to {$mode->value}.");
            }

            $s = $strategy->state();
            $this->table(['field', 'value'], [
                ['strategy', $s['strategy']],
                ['ready', $s['ready'] ? 'yes' : 'no'],
                ['blocker', $s['blocker'] ?? '—'],
                ['direct İYS API available', $s['direct_api']['available'] ? 'yes' : 'no'],
                ['ViA', $s['via']['state']],
                ['reconciliation max age (h)', $s['reconciliation']['max_age_hours'] ?? 'not set'],
                ['reconciliation format', $s['reconciliation']['format_configured'] ? 'configured' : 'not configured'],
                ['last applied report', $s['reconciliation']['last_applied']['period_end'] ?? '—'],
                ['reconciliation age (h)', $s['reconciliation']['age_hours'] ?? '—'],
            ]);

            return self::SUCCESS;
        });
    }
}
