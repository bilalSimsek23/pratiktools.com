<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignDryRun;
use App\Campaigns\CampaignError;
use App\Campaigns\CampaignReadiness;
use App\Campaigns\Campaigns;
use App\Campaigns\CampaignStatus;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   campaign:list | campaign:report | campaign:dry-run | campaign:create | campaign:state
 * None of them sends: there is no campaign dispatcher in F6.
 */
final class CampaignCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'campaign {action : list|report|dry-run|create|state} {--tenant= : Tenant slug} {--campaign= : Campaign id}
        {--name=} {--type=newsletter} {--segment=} {--template-version=} {--integration=} {--sender=} {--to= : ready|scheduled|paused|cancelled|archived} {--at= : scheduled_at}';

    protected $description = 'Campaigns: list, report (audience + readiness), dry-run, create draft, change state (no sending)';

    public function handle(Campaigns $campaigns, CampaignAudience $audience, CampaignReadiness $readiness, CampaignDryRun $dryRun): int
    {
        $actor = Actor::console('campaign:'.$this->argument('action'));

        return $this->inTenant(function () use ($campaigns, $audience, $readiness, $dryRun, $actor) {
            try {
                switch ($this->argument('action')) {
                    case 'list':
                        $this->table(['id', 'name', 'type', 'status', 'frozen'], DB::table('campaigns')->orderByDesc('created_at')->get()
                            ->map(fn ($c) => [$c->id, $c->name, $c->type, $c->status, $c->frozen_at ?? '-'])->all());
                        break;
                    case 'report':
                        $c = $campaigns->find((string) $this->option('campaign'));
                        $report = $audience->report($c);
                        $this->line(json_encode(['audience' => $report, 'readiness' => $readiness->evaluate($c, $report)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        break;
                    case 'dry-run':
                        $this->line(json_encode($dryRun->run((string) $this->option('campaign'), $actor), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        break;
                    case 'create':
                        $this->info('Draft campaign '.$campaigns->create(['name' => $this->option('name'), 'type' => $this->option('type'), 'audience_source' => 'segment',
                            'segment_id' => $this->option('segment'), 'template_version_id' => $this->option('template-version'), 'integration_id' => $this->option('integration'),
                            'sender_identity_id' => $this->option('sender')], $actor).' created.');
                        break;
                    case 'state':
                        $r = $campaigns->transition((string) $this->option('campaign'), CampaignStatus::from((string) $this->option('to')), $actor,
                            $this->option('at') ? CarbonImmutable::parse((string) $this->option('at')) : null);
                        $this->info('Done. Blocked delivery checks: '.implode(', ', $r['reason_codes'] ?? []));
                        break;
                    default:
                        $this->error('Unknown action.');

                        return self::INVALID;
                }
            } catch (CampaignError $e) {
                $this->error("[{$e->reason}] {$e->getMessage()}");

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }
}
