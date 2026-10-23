<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Consent\Sync\ConsentSynchronizer;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Jobs\SyncConsent;
use App\Models\TenantIntegration;
use Illuminate\Console\Command;

final class SyncConsentCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:sync
        {--tenant= : Tenant slug}
        {--kind= : iys_test, iys_status, iys_changes, iys_resolve or ticimax_history}
        {--budget= : Single status calls for iys_resolve}
        {--inline : Run in this process instead of queueing}';

    protected $description = 'Read-only consent sync (İYS shadow mode, store permission history)';

    public function handle(ConsentSynchronizer $sync): int
    {
        $kind = (string) $this->option('kind');

        return $this->inTenant(function (string $tenantId) use ($sync, $kind) {
            [$provider, $capability] = $kind === 'ticimax_history' ? ['ticimax', 'commerce'] : ['iys', 'consent'];
            $integration = TenantIntegration::query()->where('provider', $provider)->where('capability', $capability)->first();
            if ($integration === null) {
                $this->error("The tenant has no {$provider} {$capability} integration.");

                return self::FAILURE;
            }

            if ($kind === 'iys_test') {
                $provider = $sync->consentProvider($integration, 'connection_test');
                $brand = $provider->authenticate();
                $quota = $provider->getQuota();
                $integration->forceFill(['status' => 'connected', 'last_error' => null, 'last_verified_at' => now()])->save();
                $this->line('brand: '.json_encode($brand, JSON_UNESCAPED_UNICODE));
                $this->line('subscription: '.json_encode($quota, JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            if (! $this->option('inline')) {
                SyncConsent::dispatch($integration->id, $kind, $tenantId);
                $this->line("Queued {$kind}.");

                return self::SUCCESS;
            }

            $actor = Actor::console($this->getName());
            $runs = match ($kind) {
                'iys_status' => [$sync->iysStatus($integration, actor: $actor)],
                'iys_changes' => [$sync->iysChanges($integration, 'citizen', $actor), $sync->iysChanges($integration, 'provider', $actor)],
                'iys_resolve' => [$sync->iysResolve($integration, $this->option('budget') === null ? null : (int) $this->option('budget'), $actor)],
                'ticimax_history' => [$sync->ticimaxHistory($integration, actor: $actor)],
                default => throw new \InvalidArgumentException('Unknown --kind.'),
            };

            foreach ($runs as $run) {
                $this->line(sprintf('%-24s %-9s read=%d changed=%d same=%d api=%d %.1fs%s', $run->entity, $run->status, $run->records_read,
                    $run->records_updated, $run->records_skipped, $run->api_calls, ($run->duration_ms ?? 0) / 1000,
                    $run->error_category ? " [{$run->error_category}] {$run->error_message}" : ''));
            }

            return collect($runs)->contains(fn ($r) => $r->status === 'failed') ? self::FAILURE : self::SUCCESS;
        });
    }
}
