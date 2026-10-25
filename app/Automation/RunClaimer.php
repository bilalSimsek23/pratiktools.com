<?php

namespace App\Automation;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Atomically claims due runs of published automations: one UPDATE over a
 * FOR UPDATE SKIP LOCKED selection, each claimed row getting its own
 * random claim token and a lease. Two workers never claim the same run;
 * a run whose worker died is reclaimed after its lease expires. Runs of
 * paused automations are not claimed (they stay waiting).
 */
final class RunClaimer
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<array{id: string, claim_token: string}>
     */
    public function claim(int $limit, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->toIso8601String();
        $rows = DB::select(
            "UPDATE automation_runs r SET status = 'running', claim_token = gen_random_uuid(), claimed_until = ?::timestamptz + make_interval(secs => ?),
                    attempts = r.attempts + 1, updated_at = now()
             FROM (
                SELECT ar.id FROM automation_runs ar
                JOIN automations a ON a.tenant_id = ar.tenant_id AND a.id = ar.automation_id AND a.status = 'published'
                WHERE ar.tenant_id = ?
                  AND ((ar.status IN ('pending', 'waiting') AND (ar.resume_at IS NULL OR ar.resume_at <= ?::timestamptz))
                    OR (ar.status = 'running' AND ar.claimed_until < ?::timestamptz))
                ORDER BY ar.resume_at NULLS FIRST, ar.id
                LIMIT ?
                FOR UPDATE OF ar SKIP LOCKED
             ) due
             WHERE r.id = due.id AND r.tenant_id = ?
             RETURNING r.id, r.claim_token",
            [$now, (int) config('automation.tick.lease_seconds'), $this->context->requireTenant(), $now, $now, $limit, $this->context->requireTenant()],
        );

        return array_map(fn ($r) => ['id' => (string) $r->id, 'claim_token' => (string) $r->claim_token], $rows);
    }
}
