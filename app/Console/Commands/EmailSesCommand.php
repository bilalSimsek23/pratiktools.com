<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\FindsEmailIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\EmailMetrics;
use App\Email\EmailSendGuard;
use App\Email\SesAccountCheck;
use App\Email\SesIntegrations;
use App\Email\SnsConfirmationFailed;
use App\Email\SnsSubscriptions;
use App\Email\WarmupPlanner;
use Illuminate\Console\Command;

/**
 * Amazon SES operations:
 *   check                 read-only GetAccount (sandbox, quota, rate, status); stores it
 *   report                metrics from our tables (no provider call)
 *   warmup                proposed warm-up plan (--pool), nothing is scheduled
 *   scope                 grant or revoke send_email (audited)
 *   confirm-subscription  confirm a pending SNS subscription (explicit only)
 *
 * check, scope and confirm-subscription are the same services Super Admin ›
 * Entegrasyonlar › Amazon SES uses; here for diagnostics and break-glass.
 */
final class EmailSesCommand extends Command
{
    use FindsEmailIntegration, RunsInTenantContext;

    protected $signature = 'email:ses {action : check|report|warmup|scope|confirm-subscription} {--tenant= : Tenant slug} {--json : JSON output}
        {--grant= : scope to grant (send_email)} {--revoke= : scope to revoke (send_email)} {--pool=0 : eligible recipients for warmup}';

    protected $description = 'Amazon SES: read-only account check, report, warm-up plan, scopes';

    public function handle(SesAccountCheck $check, SesIntegrations $integrations, SnsSubscriptions $sns, EmailMetrics $metrics, WarmupPlanner $warmup): int
    {
        $actor = Actor::console('email:ses:'.$this->argument('action'));

        return $this->inTenant(function () use ($check, $integrations, $sns, $metrics, $warmup, $actor) {
            $integration = $this->emailIntegration('amazon_ses');

            switch ($this->argument('action')) {
                case 'check':
                    $started = microtime(true);
                    $result = $check->run($integration, $actor);
                    if (! $result->ok) {
                        $this->error("[{$result->errorCategory}] {$result->errorMessage}");

                        return self::FAILURE;
                    }
                    $this->line(json_encode($result->account + ['configuration_set' => $integration->settings['configuration_set_check']['state'] ?? null,
                        'duration_ms' => (int) ((microtime(true) - $started) * 1000)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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
                    $scopes = $integrations->setSendScope($integration, $grant !== null, $actor);
                    $this->info('Scopes: '.implode(', ', $scopes));

                    return self::SUCCESS;

                case 'confirm-subscription':
                    try {
                        $sns->confirm($integration, $actor);
                    } catch (SnsConfirmationFailed $e) {
                        $this->error($e->reason === 'NO_PENDING_SUBSCRIPTION' ? 'No pending SNS subscription confirmation.' : "SNS subscription not confirmed ({$e->reason}).");

                        return self::FAILURE;
                    }
                    $this->info('SNS subscription confirmed.');

                    return self::SUCCESS;

                default:
                    $this->error('Unknown action.');

                    return self::INVALID;
            }
        });
    }
}
