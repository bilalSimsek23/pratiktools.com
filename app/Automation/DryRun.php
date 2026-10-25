<?php

namespace App\Automation;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Segments\ReadOnlyTransaction;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates an automation version against real data without any effect:
 * everything runs inside a READ ONLY transaction (PostgreSQL refuses any
 * write), no provider is called and no job is dispatched. Delays are
 * simulated from the trigger time; conditions and eligibility use the
 * current data, exactly as a real run would at that step.
 *
 * Candidates: recent trigger events (email.dry_run.event_lookback_days,
 * backfilled ones included) or the time trigger's current candidates.
 */
final class DryRun
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TriggerScanner $scanner,
        private readonly TimeTriggers $time,
        private readonly StepEvaluator $evaluator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $triggerOverride  e.g. other inactivity days
     * @return array<string, mixed>
     */
    public function run(string $automationId, ?Actor $actor = null, ?array $triggerOverride = null, ?int $maxCandidates = null): array
    {
        $tenantId = $this->context->requireTenant();
        $started = hrtime(true);
        $automation = DB::table('automations')->where('tenant_id', $tenantId)->where('id', $automationId)->first()
            ?? throw new AutomationDefinitionError('NOT_FOUND', 'Unknown automation.');
        $version = DB::table('automation_versions')->where('tenant_id', $tenantId)->where('automation_id', $automationId)->orderByDesc('version')->first();
        $trigger = $triggerOverride ?? (array) json_decode($version->trigger, true);
        $steps = (array) json_decode($version->steps, true);
        $max = $maxCandidates ?? (int) config('automation.dry_run.max_candidates');

        $report = ReadOnlyTransaction::run(fn () => $this->evaluate($automation, $trigger, $steps, $max));
        $report['automation_id'] = $automationId;
        $report['version'] = (int) $version->version;
        $report['trigger'] = $trigger;
        $report['provider_calls'] = 0;
        $report['writes'] = 0;
        $report['duration_ms'] = (int) ((hrtime(true) - $started) / 1e6);

        $this->audit->record(AuditAction::AutomationDryRun, 'automation', $automationId, array_intersect_key($report, array_flip([
            'version', 'evaluated', 'entered', 'planned_email', 'planned_push', 'consent_blocked', 'suppression_blocked', 'frequency_capped', 'identity_blocked', 'duplicate_prevented',
        ])), $actor);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function evaluate(object $automation, array $trigger, array $steps, int $max): array
    {
        $type = TriggerType::from($trigger['type']);
        $r = ['evaluated' => 0, 'entered' => 0, 'completed' => 0, 'exited' => 0, 'still_waiting' => 0, 'planned_email' => 0, 'planned_push' => 0,
            'consent_blocked' => 0, 'suppression_blocked' => 0, 'frequency_capped' => 0, 'identity_blocked' => 0, 'duplicate_prevented' => 0, 'superseded' => 0,
            'exit_reasons' => [], 'blocked_reasons' => [], 'samples' => []];
        $candidates = $this->candidates($automation, $type, $trigger, $max, $r);
        $pending = [];
        $samples = (int) config('automation.dry_run.sample_traces');
        $hasIntentSteps = (bool) array_filter($steps, fn ($s) => str_starts_with((string) $s['type'], 'create_'));
        $realNow = CarbonImmutable::now();

        foreach ($candidates as $c) {
            $r['entered']++;
            $run = ['customer_id' => $c['customer_id'], 'triggered_at' => $c['triggered_at']->toIso8601String(), 'trigger_type' => $type->value, 'context' => $c['context']];
            $clock = $c['triggered_at'];
            $trace = [];
            $outcome = null;

            foreach ($steps as $step) {
                $decision = $this->evaluator->evaluate($step, $run, $clock, $pending);
                if ($decision->outcome === 'waiting') {
                    if ($decision->until->gt($realNow)) {
                        $outcome = 'still_waiting';
                        $trace[] = "{$step['key']}:waiting";
                        break;
                    }
                    $clock = $decision->until;
                    $decision = $this->evaluator->evaluate($step, $run, $clock, $pending);
                }
                $trace[] = $step['key'].':'.$decision->outcome.($decision->reason !== null ? '('.$decision->reason.')' : '');
                if (($counter = AutomationMetrics::categoryCounter($decision->category)) !== null) {
                    $r[$counter]++;
                }
                if ($decision->outcome === 'blocked') {
                    $r['blocked_reasons'][$decision->reason] = ($r['blocked_reasons'][$decision->reason] ?? 0) + 1;
                }
                if ($decision->outcome === 'exited') {
                    $outcome = 'exited:'.$decision->reason;
                    break;
                }
                if ($decision->outcome === 'intent_created') {
                    $channel = $decision->intent['channel'];
                    $r['planned_'.$channel]++;
                    $run['context']['intents'] = ($run['context']['intents'] ?? 0) + 1;
                    $ledger = $channel === 'email' ? "email:{$decision->intent['recipient_ref']}" : "push:{$c['customer_id']}";
                    $pending[$ledger] = ($pending[$ledger] ?? 0) + 1;
                }
            }

            $outcome ??= $hasIntentSteps && ($run['context']['intents'] ?? 0) === 0 ? 'exited:no_eligible_channel' : 'completed';
            if (str_starts_with($outcome, 'exited:')) {
                $r['exited']++;
                $reason = substr($outcome, 7);
                $r['exit_reasons'][$reason] = ($r['exit_reasons'][$reason] ?? 0) + 1;
            } else {
                $r[$outcome]++;
            }
            if (count($r['samples']) < $samples) {
                $r['samples'][] = ['subject' => 'cust_'.substr(hash('sha256', $c['customer_id']), 0, 10), 'outcome' => $outcome, 'steps' => $trace];
            }
        }
        arsort($r['exit_reasons']);
        arsort($r['blocked_reasons']);

        return $r;
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>  $r
     * @return list<array{customer_id: string, triggered_at: CarbonImmutable, context: array<string, mixed>}>
     */
    private function candidates(object $automation, TriggerType $type, array $trigger, int $max, array &$r): array
    {
        $tenantId = $this->context->requireTenant();
        $existing = fn (string $ref) => DB::table('automation_runs')->where('tenant_id', $tenantId)->where('automation_id', $automation->id)
            ->where('idempotency_key', RunFactory::key($automation->id, $type->value, $ref))->exists();
        $out = [];

        if ($type->isTimeBased()) {
            $fake = (object) ['id' => $automation->id, 'trigger' => $trigger];
            foreach ($this->time->candidates($fake, $max, includeExisting: true) as $c) {
                $r['evaluated']++;
                if ($existing($c['ref'])) {
                    $r['duplicate_prevented']++;

                    continue;
                }
                $out[] = ['customer_id' => $c['customer_id'], 'triggered_at' => CarbonImmutable::now(), 'context' => $c['context']];
            }

            return $out;
        }

        $since = CarbonImmutable::now()->subDays((int) config('automation.dry_run.event_lookback_days'));
        $rows = $this->scanner->rows((string) $type->source(), null, $max, $since);
        $latest = [];
        $seen = [];
        foreach ($rows as $row) {
            $m = TriggerScanner::map((string) $type->source(), $row, $type, (array) ($trigger['params'] ?? []));
            if ($m === null) {
                continue;
            }
            $r['evaluated']++;
            if ($m['customer_id'] === null) {
                $r['identity_blocked']++;

                continue;
            }
            if (isset($seen[$m['ref']]) || $existing($m['ref'])) {
                $r['duplicate_prevented']++;

                continue;
            }
            $seen[$m['ref']] = true;
            // One active run per scope: a newer event supersedes an older one (carts),
            // otherwise the first one wins.
            if (isset($latest[$m['scope']])) {
                if ($type === TriggerType::CartAbandoned) {
                    $r['superseded']++;
                    $latest[$m['scope']] = ['customer_id' => $m['customer_id'], 'triggered_at' => CarbonImmutable::parse($row->occurred_at), 'context' => $m['context']];
                } else {
                    $r['duplicate_prevented']++;
                }

                continue;
            }
            $latest[$m['scope']] = ['customer_id' => $m['customer_id'], 'triggered_at' => CarbonImmutable::parse($row->occurred_at), 'context' => $m['context']];
        }

        return array_values($latest);
    }
}
