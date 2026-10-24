<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\Ses\SesWebhookHandler;
use App\Console\Commands\Concerns\FindsEmailIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\EmailMetrics;
use App\Email\EmailProviderResolver;
use App\Email\EmailSendGuard;
use App\Email\WarmupPlanner;
use App\Models\WebhookInboxItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Amazon SES operations:
 *   check                 read-only GetAccount (sandbox, quota, rate, status); stores it
 *   report                metrics from our tables (no provider call)
 *   warmup                proposed warm-up plan (--pool), nothing is scheduled
 *   scope                 grant or revoke send_email (audited)
 *   confirm-subscription  confirm a pending SNS subscription (explicit only)
 */
final class EmailSesCommand extends Command
{
    use FindsEmailIntegration, RunsInTenantContext;

    protected $signature = 'email:ses {action : check|report|warmup|scope|confirm-subscription} {--tenant= : Tenant slug} {--json : JSON output}
        {--grant= : scope to grant (send_email)} {--revoke= : scope to revoke (send_email)} {--pool=0 : eligible recipients for warmup}';

    protected $description = 'Amazon SES: read-only account check, report, warm-up plan, scopes';

    public function handle(EmailProviderResolver $providers, EmailMetrics $metrics, WarmupPlanner $warmup, AuditLogger $audit): int
    {
        $actor = Actor::console('email:ses:'.$this->argument('action'));

        return $this->inTenant(function () use ($providers, $metrics, $warmup, $audit, $actor) {
            $integration = $this->emailIntegration('amazon_ses');

            switch ($this->argument('action')) {
                case 'check':
                    $started = microtime(true);
                    $provider = $providers->provider($integration, 'connection_test', $actor);
                    $result = $provider->testConnection();
                    if (! $result->ok) {
                        $integration->forceFill(['status' => $result->errorCategory === 'auth' ? 'action_required' : 'error', 'last_error' => $result->errorMessage])->save();
                        $this->error("[{$result->errorCategory}] {$result->errorMessage}");

                        return self::FAILURE;
                    }
                    $integration->forceFill(['status' => 'connected', 'last_error' => null, 'last_verified_at' => now(),
                        'settings' => ['account' => $result->account] + (array) $integration->settings])->save();
                    $audit->record(AuditAction::EmailAccountChecked, 'tenant_integration', $integration->id, array_intersect_key($result->account,
                        array_flip(['region', 'production_access', 'sending_enabled', 'enforcement_status', 'max_24h_send', 'max_send_rate'])), $actor);
                    $this->line(json_encode($result->account + ['duration_ms' => (int) ((microtime(true) - $started) * 1000)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                    return self::SUCCESS;

                case 'report':
                    $this->line(json_encode($metrics->for($integration->fresh()), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                    return self::SUCCESS;

                case 'warmup':
                    $plan = $warmup->propose((int) $this->option('pool'), (float) ($integration->settings['account']['max_24h_send'] ?? 0));
                    $this->table(['day', 'volume', 'segment'], array_map(fn ($d) => [$d['day'], $d['volume'], $d['segment']], $plan));

                    return self::SUCCESS;

                case 'scope':
                    $grant = $this->option('grant');
                    $revoke = $this->option('revoke');
                    if (! in_array($grant ?? $revoke, [EmailSendGuard::SCOPE], true) || ($grant !== null && $revoke !== null)) {
                        $this->error('Use --grant=send_email or --revoke=send_email.');

                        return self::INVALID;
                    }
                    $scopes = array_values(array_unique($grant !== null ? [...(array) $integration->scopes, $grant] : array_diff((array) $integration->scopes, [$revoke])));
                    $integration->forceFill(['scopes' => $scopes])->save();
                    $audit->record(AuditAction::IntegrationScopeChanged, 'tenant_integration', $integration->id, ['granted' => $grant, 'revoked' => $revoke, 'scopes' => $scopes], $actor);
                    $this->info('Scopes: '.implode(', ', $scopes));

                    return self::SUCCESS;

                case 'confirm-subscription':
                    $pending = (array) ($integration->settings['sns_subscription'] ?? []);
                    $item = isset($pending['inbox_id']) && ($pending['state'] ?? null) === 'pending_confirmation' ? WebhookInboxItem::query()->find($pending['inbox_id']) : null;
                    $url = (string) ($item?->payload['SubscribeURL'] ?? '');
                    if (! SesWebhookHandler::trustedSubscribeUrl($url)) {
                        $this->error('No pending SNS subscription confirmation.');

                        return self::FAILURE;
                    }
                    Http::timeout(10)->get($url)->throw();
                    $integration->forceFill(['settings' => ['sns_subscription' => ['state' => 'confirmed', 'at' => now()->toIso8601String()] + $pending] + (array) $integration->settings])->save();
                    $this->info('SNS subscription confirmed.');

                    return self::SUCCESS;

                default:
                    $this->error('Unknown action.');

                    return self::INVALID;
            }
        });
    }
}
