<?php

namespace App\Automation;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Advances one claimed run through its version's steps until it waits,
 * exits or completes. Everything of one advance (step rows, planned
 * actions, run state) commits in one transaction and only if this worker
 * still holds the claim token; otherwise nothing is written.
 *
 * Planned actions are unique per (run, step); they are records, never
 * sends: no provider is called and no send job is dispatched here.
 */
final class RunProcessor
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StepEvaluator $evaluator,
        private readonly AutomationMetrics $metrics,
    ) {}

    /**
     * @return string final or waiting status of the run
     */
    public function process(string $runId, string $claimToken, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $tenantId = $this->context->requireTenant();
        $started = hrtime(true);
        $row = DB::table('automation_runs')->where('tenant_id', $tenantId)->where('id', $runId)->where('claim_token', $claimToken)->first();
        if ($row === null) {
            throw new LeaseLost;
        }

        try {
            return DB::transaction(function () use ($row, $claimToken, $now, $tenantId, $started) {
                $automation = DB::table('automations')->where('tenant_id', $tenantId)->where('id', $row->automation_id)->first();
                if ($automation->status === AutomationStatus::Archived->value) {
                    return $this->finish($row, $claimToken, 'cancelled', 'automation_archived', [], $started);
                }
                if ($automation->status !== AutomationStatus::Published->value) {
                    // Paused: hold the run as it is.
                    $this->update($row, $claimToken, ['status' => 'waiting', 'claim_token' => null, 'claimed_until' => null]);

                    return 'waiting';
                }

                $version = DB::table('automation_versions')->where('tenant_id', $tenantId)->where('id', $row->version_id)->first();
                $steps = (array) json_decode($version->steps, true);
                $run = ['customer_id' => $row->customer_id, 'triggered_at' => $row->triggered_at, 'trigger_type' => $row->trigger_type, 'context' => (array) json_decode($row->context, true)];
                $counts = ['steps_processed' => 0];
                $hasIntentSteps = (bool) array_filter($steps, fn ($s) => str_starts_with((string) $s['type'], 'create_'));

                for ($i = (int) $row->current_step; $i < count($steps); $i++) {
                    $step = $steps[$i];
                    $decision = $this->evaluator->evaluate($step, $run, $now);
                    $counts['steps_processed']++;

                    if ($decision->outcome === 'waiting') {
                        $this->update($row, $claimToken, ['status' => 'waiting', 'current_step' => $i, 'resume_at' => $decision->until, 'context' => json_encode((object) $run['context']),
                            'claim_token' => null, 'claimed_until' => null]);
                        $this->stepRow($row, $i, $step, 'waiting', null);
                        $this->metrics->add($row->automation_id, $counts);

                        return 'waiting';
                    }

                    $this->stepRow($row, $i, $step, $decision->outcome, $decision->reason);
                    if (($counter = AutomationMetrics::categoryCounter($decision->category)) !== null) {
                        $counts[$counter] = ($counts[$counter] ?? 0) + 1;
                    }

                    if ($decision->outcome === 'exited') {
                        $this->metrics->add($row->automation_id, $counts);

                        return $this->finish($row, $claimToken, 'exited', $decision->reason, $run['context'], $started, $i);
                    }
                    if ($decision->outcome === 'intent_created') {
                        if ($this->plan($row, $version, $step, $decision->intent)) {
                            $run['context']['intents'] = ($run['context']['intents'] ?? 0) + 1;
                            $counts['planned_'.$decision->intent['channel']] = ($counts['planned_'.$decision->intent['channel']] ?? 0) + 1;
                        } else {
                            $counts['duplicate_prevented'] = ($counts['duplicate_prevented'] ?? 0) + 1;
                        }
                    }
                }

                $this->metrics->add($row->automation_id, $counts);
                $none = $hasIntentSteps && ($run['context']['intents'] ?? 0) === 0;

                return $this->finish($row, $claimToken, $none ? 'exited' : 'completed', $none ? 'no_eligible_channel' : null, $run['context'], $started, count($steps));
            });
        } catch (LeaseLost $e) {
            throw $e;
        } catch (Throwable $e) {
            $failed = $row->attempts >= self::MAX_ATTEMPTS;
            $this->update($row, $claimToken, $failed
                ? ['status' => 'failed', 'error_code' => mb_substr(class_basename($e), 0, 64), 'completed_at' => now(), 'claim_token' => null]
                : ['status' => 'waiting', 'resume_at' => now()->addMinutes(5 * $row->attempts), 'error_code' => mb_substr(class_basename($e), 0, 64), 'claim_token' => null, 'claimed_until' => null]);
            if ($failed) {
                $this->metrics->add($row->automation_id, ['runs_failed' => 1]);
            }
            report($e);

            return $failed ? 'failed' : 'waiting';
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $intent
     */
    private function plan(object $row, object $version, array $step, array $intent): bool
    {
        return DB::affectingStatement(
            'INSERT INTO automation_planned_actions (id, tenant_id, run_id, automation_id, version_id, step_key, channel, message_type, customer_id, recipient_ref, idempotency_key, status, payload, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'planned\', ?::jsonb, now()) ON CONFLICT (tenant_id, idempotency_key) DO NOTHING',
            [(string) Str::uuid7(), $row->tenant_id, $row->id, $row->automation_id, $version->id, $step['key'], $intent['channel'], $intent['message_type'], $row->customer_id,
                $intent['recipient_ref'], hash('sha256', "{$row->id}|{$step['key']}"), json_encode((object) $intent['payload'])],
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepRow(object $row, int $index, array $step, string $outcome, ?string $reason): void
    {
        DB::statement(
            'INSERT INTO automation_run_steps (id, tenant_id, run_id, step_index, step_key, type, outcome, reason, executed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, now())
             ON CONFLICT (tenant_id, run_id, step_key) DO UPDATE SET outcome = EXCLUDED.outcome, reason = EXCLUDED.reason, executed_at = now()',
            [(string) Str::uuid7(), $row->tenant_id, $row->id, $index, $step['key'], $step['type'], $outcome, $reason === null ? null : mb_substr($reason, 0, 48)],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function finish(object $row, string $claimToken, string $status, ?string $reason, array $context, int $started, ?int $step = null): string
    {
        $this->update($row, $claimToken, array_filter(['status' => $status, 'exit_reason' => $reason === null ? null : mb_substr($reason, 0, 48), 'completed_at' => now(),
            'context' => $context === [] ? null : json_encode((object) $context), 'current_step' => $step, 'resume_at' => null, 'claim_token' => null, 'claimed_until' => null], fn ($v) => $v !== null)
            + ['claim_token' => null, 'resume_at' => null, 'claimed_until' => null]);
        $this->metrics->add($row->automation_id, ['runs_'.$status => 1, 'latency_ms_sum' => (int) ((hrtime(true) - $started) / 1e6), 'latency_count' => 1]);

        return $status;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(object $row, string $claimToken, array $values): void
    {
        $updated = DB::table('automation_runs')->where('tenant_id', $row->tenant_id)->where('id', $row->id)->where('claim_token', $claimToken)
            ->update($values + ['updated_at' => now()]);
        if ($updated !== 1) {
            throw new LeaseLost;
        }
    }
}
