<?php

namespace App\Automation;

use App\Segments\SegmentCompiler;
use App\Segments\Segments;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Time-based triggers, evaluated in the database in bounded batches:
 *
 * - no_purchase_for {days, catch_up_days}: customers whose last order
 *   crossed the threshold within the catch-up window (not the whole
 *   history every time). One run per inactivity episode: the reference is
 *   the date of the last order, so a new order starts a new episode.
 * - segment_periodic {segment_id, reentry_days}: current segment members,
 *   at most once per re-entry period.
 *
 * Candidates that already have a run with the same idempotency key are
 * excluded in SQL (sha256 of the same key), so each tick advances.
 */
final class TimeTriggers
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SegmentCompiler $compiler,
        private readonly Segments $segments,
        private readonly RunFactory $runs,
    ) {}

    /**
     * @return array<string, int> automation id => runs considered
     */
    public function evaluate(TriggerScanner $scanner, bool $force = false): array
    {
        $done = [];
        $interval = (int) config('automation.tick.time_trigger_interval_minutes');
        foreach ($scanner->published() as $automation) {
            $type = TriggerType::from($automation->trigger['type']);
            if (! $type->isTimeBased() || (! $force && $automation->last_evaluated_at !== null && CarbonImmutable::parse($automation->last_evaluated_at)->gt(now()->subMinutes($interval)))) {
                continue;
            }
            $candidates = $this->candidates($automation, (int) config('automation.tick.time_trigger_batch'));
            foreach ($candidates as $c) {
                $this->runs->start($automation, $type, $c['ref'], $c['customer_id'], 'customer:'.$c['customer_id'], CarbonImmutable::now(), $c['context']);
            }
            $done[$automation->id] = count($candidates);
            if (count($candidates) < (int) config('automation.tick.time_trigger_batch')) {
                DB::table('automations')->where('tenant_id', $this->context->requireTenant())->where('id', $automation->id)->update(['last_evaluated_at' => now()]);
            }
        }

        return $done;
    }

    /**
     * @return list<array{customer_id: string, ref: string, context: array<string, mixed>}>
     */
    public function candidates(object $automation, int $limit, bool $includeExisting = false): array
    {
        $tenantId = $this->context->requireTenant();
        $params = (array) ($automation->trigger['params'] ?? []);
        $type = TriggerType::from($automation->trigger['type']);
        $audience = (array) ($params['audience'] ?? ['all' => [['field' => 'order_count', 'op' => 'gte', 'value' => 0]]]);
        $prefix = $automation->id.'|'.$type->value.'|';

        if ($type === TriggerType::NoPurchaseFor) {
            $days = (int) $params['days'];
            $catchUp = (int) ($params['catch_up_days'] ?? 7);
            $ref = "'winback:{$days}:' || to_char(s.last_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD')";
            $extra = 'AND s.last_order_at < now() - make_interval(days => ?) AND s.last_order_at >= now() - make_interval(days => ?)'
                .($includeExisting ? '' : " AND NOT EXISTS (SELECT 1 FROM automation_runs r WHERE r.tenant_id = c.tenant_id AND r.automation_id = ?::uuid
                    AND r.idempotency_key = encode(sha256(convert_to(? || {$ref}, 'UTF8')), 'hex'))")
                .' ORDER BY c.id LIMIT '.$limit;
            $q = $this->compiler->select($audience, $tenantId, "c.id, s.last_order_at, {$ref} AS ref", $extra,
                $includeExisting ? [$days, $days + $catchUp] : [$days, $days + $catchUp, $automation->id, $prefix]);

            return array_map(fn ($r) => ['customer_id' => (string) $r->id, 'ref' => (string) $r->ref, 'context' => ['since' => $r->last_order_at, 'inactive_days' => $days]], DB::select($q['sql'], $q['bindings']));
        }

        // segment_periodic
        $definition = $this->segments->definition((string) $params['segment_id']);
        $bucket = intdiv(now()->getTimestamp(), 86400 * (int) $params['reentry_days']);
        $refValue = "segment:{$params['segment_id']}:{$bucket}";
        $extra = ($includeExisting ? '' : " AND NOT EXISTS (SELECT 1 FROM automation_runs r WHERE r.tenant_id = c.tenant_id AND r.automation_id = ?::uuid
                AND r.idempotency_key = encode(sha256(convert_to(?, 'UTF8')), 'hex'))").' ORDER BY c.id LIMIT '.$limit;
        $q = $this->compiler->select($definition, $tenantId, 'c.id', $extra, $includeExisting ? [] : [$automation->id, $prefix.$refValue]);

        return array_map(fn ($r) => ['customer_id' => (string) $r->id, 'ref' => $refValue, 'context' => ['segment_id' => $params['segment_id']]], DB::select($q['sql'], $q['bindings']));
    }
}
