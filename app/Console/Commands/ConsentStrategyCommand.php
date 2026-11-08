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
 * fail-closed until the chosen strategy is operational, and the sending
 * modes are untouched.
 *
 * merchant_verified (the tenant's store permissions, attested; not an İYS
 * verification) also needs --evidence-ref (where the tenant keeps the
 * proof) and --operator (who verified it).
 */
final class ConsentStrategyCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:strategy {--tenant= : Tenant slug} {--set= : direct_api|via_reconciliation|merchant_verified} {--reason= : Why (required with --set)}
        {--evidence-ref= : merchant_verified: where the tenant keeps the proof of its store consents}
        {--operator= : merchant_verified: who verified the store consents}';

    protected $description = 'Show or change the consent strategy of a tenant (İYS API, ViA + daily reconciliation, or attested store permissions)';

    public function handle(ConsentStrategy $strategy): int
    {
        return $this->inTenant(function () use ($strategy): int {
            if ($this->option('set') !== null) {
                $mode = ConsentStrategyMode::tryFrom((string) $this->option('set'));
                if ($mode === null || trim((string) $this->option('reason')) === '') {
                    $this->error('Pass --set=direct_api|via_reconciliation|merchant_verified and --reason.');

                    return self::FAILURE;
                }
                if ($mode === ConsentStrategyMode::MerchantVerified && (trim((string) $this->option('evidence-ref')) === '' || trim((string) $this->option('operator')) === '')) {
                    $this->error('merchant_verified needs --evidence-ref and --operator.');

                    return self::FAILURE;
                }
                $strategy->set($mode, (string) $this->option('reason'), Actor::console($this->getName()),
                    $this->option('evidence-ref') === null ? null : (string) $this->option('evidence-ref'),
                    $this->option('operator') === null ? null : (string) $this->option('operator'));
                $this->info("Consent strategy set to {$mode->value}.");
            }

            $s = $strategy->state();
            $m = $s['merchant'];
            $this->table(['field', 'value'], [
                ['strategy', $s['strategy']],
                ['source', $s['source_label']],
                ['ready', $s['ready'] ? 'yes' : 'no'],
                ['blocker', $s['blocker'] ?? '—'],
                ['direct İYS API available', $s['direct_api']['available'] ? 'yes' : 'no'],
                ['ViA', $s['via']['state']],
                ['reconciliation max age (h)', $s['reconciliation']['max_age_hours'] ?? 'not set'],
                ['reconciliation format', $s['reconciliation']['format_configured'] ? 'configured' : 'not configured'],
                ['last applied report', $s['reconciliation']['last_applied']['period_end'] ?? '—'],
                ['reconciliation age (h)', $s['reconciliation']['age_hours'] ?? '—'],
                ...($m === null ? [] : [
                    ['merchant attestation', $m['attestation'] === null ? 'missing' : $m['attestation']['operator'].' · '.$m['attestation']['attested_at'].' · '.$m['attestation']['evidence_ref']],
                    ['store integration', $m['commerce_connected'] ? 'connected' : 'not connected'],
                    ['store permission sync', ($m['last_sync_at'] ?? 'never').' ('.($m['sync_age_hours'] ?? '—').' h, max '.($m['max_age_hours'] ?? 'not set').' h)'],
                ]),
            ]);

            return self::SUCCESS;
        });
    }
}
