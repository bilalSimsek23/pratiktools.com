<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\OneSignal\OneSignalClientFactory;
use App\Console\Commands\Concerns\FindsOneSignalIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Integrations\Errors\ProviderError;
use App\Jobs\SyncOneSignal;
use App\Push\OneSignalSync;
use App\Push\PushMetrics;
use App\Push\PushSendGuard;
use Illuminate\Console\Command;

/**
 * Read-only OneSignal operations and integration settings.
 *   check     view app (counts), sets the integration connected
 *   sync      full read-only sync (queued, or --inline)
 *   report    metrics and external id formats from our tables
 *   strategy  external id strategy: none | ticimax_member_id | email
 *   scope     grant or revoke send_push (audited)
 */
final class OneSignalCommand extends Command
{
    use FindsOneSignalIntegration, RunsInTenantContext;

    protected $signature = 'onesignal {action : check|sync|report|strategy|scope}
        {--tenant= : Tenant slug}
        {--inline : sync in this process}
        {--json : report as JSON}
        {--strategy= : none, ticimax_member_id or email}
        {--grant= : scope to grant (send_push)}
        {--revoke= : scope to revoke (send_push)}';

    protected $description = 'OneSignal: read-only check, sync and report; integration settings';

    public function handle(OneSignalClientFactory $clients, OneSignalSync $sync, PushMetrics $metrics, AuditLogger $audit): int
    {
        $actor = Actor::console('onesignal:'.$this->argument('action'));

        return $this->inTenant(function (string $tenantId) use ($clients, $sync, $metrics, $audit, $actor) {
            $integration = $this->oneSignalIntegration();

            switch ($this->argument('action')) {
                case 'check':
                    try {
                        $client = $clients->read($integration, 'connection_test', $actor);
                        $app = $client->viewApp();
                    } catch (ProviderError $e) {
                        $integration->forceFill(['status' => $e->category === ProviderError::AUTH ? 'action_required' : 'error', 'last_error' => $e->getMessage()])->save();
                        $this->error("[{$e->category}] {$e->getMessage()}");

                        return self::FAILURE;
                    }
                    $integration->forceFill(['status' => 'connected', 'last_error' => null, 'last_verified_at' => now()])->save();
                    $audit->record(AuditAction::IntegrationVerified, 'tenant_integration', $integration->id, ['result' => 'connected'], $actor);
                    $this->line(json_encode($app + ['api_calls' => $client->transport()->apiCalls()], JSON_UNESCAPED_UNICODE));

                    return self::SUCCESS;

                case 'sync':
                    if (! $this->option('inline')) {
                        SyncOneSignal::dispatch($integration->id, $tenantId);
                        $this->line('Queued.');

                        return self::SUCCESS;
                    }
                    $started = microtime(true);
                    $run = $sync->run($integration, actor: $actor);
                    $this->line(sprintf('%s read=%d stored=%d skipped=%d api=%d %.1fs peak_memory=%dMB%s', $run->status, $run->records_read, $run->records_updated,
                        $run->records_skipped, $run->api_calls, microtime(true) - $started, intdiv(memory_get_peak_usage(true), 1048576),
                        $run->error_category ? " [{$run->error_category}] {$run->error_message}" : ''));

                    return $run->status === 'succeeded' ? self::SUCCESS : self::FAILURE;

                case 'report':
                    $report = $metrics->for($integration->fresh()) + ['external_id_formats' => $metrics->externalIdFormats($integration)];
                    $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    return self::SUCCESS;

                case 'strategy':
                    $strategy = (string) $this->option('strategy');
                    if (! in_array($strategy, ['none', 'ticimax_member_id', 'email'], true)) {
                        $this->error('--strategy must be none, ticimax_member_id or email.');

                        return self::INVALID;
                    }
                    $integration->forceFill(['settings' => ['external_id_strategy' => $strategy] + (array) $integration->settings])->save();
                    $audit->record(AuditAction::IntegrationScopeChanged, 'tenant_integration', $integration->id, ['external_id_strategy' => $strategy], $actor);
                    $this->info("External id strategy: {$strategy}. Run onesignal sync to re-resolve.");

                    return self::SUCCESS;

                case 'scope':
                    $grant = $this->option('grant');
                    $revoke = $this->option('revoke');
                    $scope = (string) ($grant ?? $revoke);
                    if ($scope !== PushSendGuard::SCOPE || ($grant !== null) === ($revoke !== null)) {
                        $this->error('Use --grant=send_push or --revoke=send_push.');

                        return self::INVALID;
                    }
                    $scopes = array_values(array_diff((array) $integration->scopes, [$scope]));
                    if ($grant !== null) {
                        $scopes[] = $scope;
                    }
                    $integration->forceFill(['scopes' => $scopes])->save();
                    $audit->record(AuditAction::IntegrationScopeChanged, 'tenant_integration', $integration->id, ['scope' => $scope, 'granted' => $grant !== null], $actor);
                    $this->info(($grant !== null ? 'Granted ' : 'Revoked ').$scope.'. Sending still needs PUSH_SENDING_MODE and the tenant sending_mode.');

                    return self::SUCCESS;
            }

            $this->error('Unknown action.');

            return self::INVALID;
        });
    }
}
