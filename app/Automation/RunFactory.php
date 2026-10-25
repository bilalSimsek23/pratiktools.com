<?php

namespace App\Automation;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts runs. The idempotency key is deterministic
 * (sha256(automation | trigger type | trigger ref)); the database refuses
 * a second run with the same key and a second active run in the same
 * scope (partial unique index), so replays, queue retries and overlapping
 * scheduler ticks cannot start duplicates.
 *
 * Carts: a newer cart state supersedes an older waiting run of the same
 * cart (the customer is still shopping), never the other way round.
 */
final class RunFactory
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AutomationMetrics $metrics,
    ) {}

    public static function key(string $automationId, string $triggerType, string $triggerRef): string
    {
        return hash('sha256', "{$automationId}|{$triggerType}|{$triggerRef}");
    }

    /**
     * @param  array<string, mixed>  $context
     * @return 'started'|'duplicate'|'active'
     */
    public function start(object $automation, TriggerType $type, string $triggerRef, string $customerId, string $scopeKey, CarbonImmutable $triggeredAt, array $context = []): string
    {
        $tenantId = $this->context->requireTenant();
        $key = self::key($automation->id, $type->value, $triggerRef);

        if (DB::table('automation_runs')->where('tenant_id', $tenantId)->where('automation_id', $automation->id)->where('idempotency_key', $key)->exists()) {
            $this->metrics->add($automation->id, ['duplicate_prevented' => 1]);

            return 'duplicate';
        }

        return DB::transaction(function () use ($tenantId, $automation, $type, $triggerRef, $customerId, $scopeKey, $triggeredAt, $context, $key) {
            if ($type === TriggerType::CartAbandoned) {
                $superseded = DB::table('automation_runs')->where('tenant_id', $tenantId)->where('automation_id', $automation->id)->where('scope_key', $scopeKey)
                    ->whereIn('status', ['pending', 'waiting'])->where('triggered_at', '<', $triggeredAt)
                    ->update(['status' => 'exited', 'exit_reason' => 'superseded', 'completed_at' => now(), 'claim_token' => null, 'updated_at' => now()]);
                if ($superseded > 0) {
                    $this->metrics->add($automation->id, ['runs_exited' => $superseded]);
                }
            }

            $id = DB::selectOne(
                'INSERT INTO automation_runs (id, tenant_id, automation_id, version_id, trigger_type, trigger_ref, idempotency_key, scope_key, customer_id, status, context,
                    triggered_at, started_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?::jsonb, ?, now(), now(), now()) ON CONFLICT DO NOTHING RETURNING id',
                [(string) Str::uuid7(), $tenantId, $automation->id, $automation->published_version_id, $type->value, mb_substr($triggerRef, 0, 191), $key, $scopeKey, $customerId,
                    json_encode((object) $context), $triggeredAt],
            );
            if ($id === null) {
                $this->metrics->add($automation->id, ['duplicate_prevented' => 1]);

                return 'active';
            }
            $this->metrics->add($automation->id, ['runs_started' => 1]);

            return 'started';
        });
    }
}
