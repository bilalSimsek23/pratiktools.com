<?php

namespace App\Segments;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Consent\SendEligibility;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant segments. Dynamic segments are evaluated in the database at each
 * use; snapshot segments freeze their members once (no UPDATE/DELETE
 * grant on snapshot tables). Large segments are never loaded into PHP:
 * counts happen in SQL, iteration is keyset-paginated by customer id.
 */
final class Segments
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SegmentCompiler $compiler,
        private readonly SendEligibility $eligibility,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function create(string $name, string $type, array $definition, Actor $actor): string
    {
        if (! in_array($type, ['dynamic', 'snapshot'], true) || trim($name) === '' || mb_strlen($name) > 120) {
            throw new SegmentRuleError('INVALID_NODE', '$', 'A segment needs a name and type dynamic or snapshot.');
        }
        $this->compiler->compile($definition);
        $id = (string) Str::uuid7();
        DB::table('segments')->insert(['id' => $id, 'tenant_id' => $this->context->requireTenant(), 'name' => trim($name), 'type' => $type,
            'definition' => json_encode($definition), 'created_by' => self::by($actor), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record(AuditAction::SegmentCreated, 'segment', $id, ['type' => $type, 'rules' => $this->compiler->compile($definition)['rules']], $actor);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $segmentId): array
    {
        $raw = DB::table('segments')->where('tenant_id', $this->context->requireTenant())->where('id', $segmentId)->value('definition')
            ?? throw new SegmentRuleError('INVALID_NODE', '$', 'Unknown segment.');

        return (array) json_decode((string) $raw, true);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function count(array $definition): int
    {
        $q = $this->compiler->select($definition, $this->context->requireTenant(), 'count(*) AS n');

        return (int) DB::selectOne($q['sql'], $q['bindings'])->n;
    }

    /**
     * Does one customer match (at evaluation time)?
     *
     * @param  array<string, mixed>  $definition
     */
    public function matches(array $definition, string $customerId): bool
    {
        $q = $this->compiler->select($definition, $this->context->requireTenant(), '1 AS ok', 'AND c.id = ?', [$customerId]);

        return DB::selectOne($q['sql'], $q['bindings']) !== null;
    }

    /**
     * Read-only preview: matched customers and why they could not be
     * reached per channel. Counts only, no personal data.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function preview(array $definition): array
    {
        $tenantId = $this->context->requireTenant();
        $started = hrtime(true);

        return ReadOnlyTransaction::run(function () use ($definition, $tenantId, $started) {
            $q = $this->compiler->select($definition, $tenantId);
            $matched = (int) DB::selectOne("SELECT count(*) AS n FROM ({$q['sql']}) m", $q['bindings'])->n;

            $withEmail = (int) DB::selectOne("SELECT count(DISTINCT rc.customer_id) AS n FROM recipient_customers rc
                JOIN consent_recipients r ON r.tenant_id = rc.tenant_id AND r.id = rc.recipient_id AND r.address_type = 'email'
                WHERE rc.tenant_id = ? AND rc.customer_id IN ({$q['sql']})", [$tenantId, ...$q['bindings']])->n;
            $emailReasons = $this->eligibility->reasonCounts(
                "SELECT rc.recipient_id FROM recipient_customers rc WHERE rc.tenant_id = ? AND rc.customer_id IN ({$q['sql']})", [$tenantId, ...$q['bindings']]);
            $emailEligible = (int) DB::selectOne("SELECT count(DISTINCT rc.customer_id) AS n FROM recipient_customers rc
                JOIN ({$this->eligibility->sql()}) e ON e.id = rc.recipient_id WHERE e.reason = 'ALLOWED' AND rc.customer_id IN ({$q['sql']})",
                [...$this->eligibility->bindings(), ...$q['bindings']])->n;

            $push = DB::selectOne("SELECT count(DISTINCT pp.customer_id) FILTER (WHERE ps.status = 'subscribed' AND ps.removed_at IS NULL
                    AND NOT EXISTS (SELECT 1 FROM push_suppressions x WHERE x.tenant_id = pp.tenant_id AND x.push_profile_id = pp.id AND x.lifted_at IS NULL)) AS eligible,
                    count(DISTINCT pp.customer_id) AS linked
                FROM push_profiles pp LEFT JOIN push_subscriptions ps ON ps.tenant_id = pp.tenant_id AND ps.push_profile_id = pp.id
                WHERE pp.tenant_id = ? AND pp.customer_id IN ({$q['sql']})", [$tenantId, ...$q['bindings']]);

            $emailExcluded = ['NO_EMAIL_RECIPIENT' => $matched - $withEmail] + array_diff_key($emailReasons, ['ALLOWED' => true]);
            $pushExcluded = ['NO_PUSH_IDENTITY' => $matched - (int) $push->linked, 'NOT_SUBSCRIBED_OR_SUPPRESSED' => (int) $push->linked - (int) $push->eligible];

            return [
                'matched' => $matched,
                'estimated_members' => $matched,
                'email' => ['eligible' => $emailEligible, 'excluded' => $matched - $emailEligible, 'reasons' => array_filter($emailExcluded), 'recipient_reasons' => $emailReasons],
                'push' => ['eligible' => (int) $push->eligible, 'excluded' => $matched - (int) $push->eligible, 'reasons' => array_filter($pushExcluded)],
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            ];
        });
    }

    /**
     * Matching customer ids, keyset-paginated (never the whole set in memory).
     *
     * @param  array<string, mixed>  $definition
     * @param  Closure(list<string>): void  $callback
     */
    public function eachChunk(array $definition, int $size, Closure $callback, ?int $limit = null): int
    {
        $tenantId = $this->context->requireTenant();
        $last = '00000000-0000-0000-0000-000000000000';
        $seen = 0;
        do {
            $q = $this->compiler->select($definition, $tenantId, 'c.id', 'AND c.id > ? ORDER BY c.id LIMIT '.(int) $size, [$last]);
            $ids = array_map(fn ($r) => (string) $r->id, DB::select($q['sql'], $q['bindings']));
            if ($ids === []) {
                break;
            }
            if ($limit !== null && $seen + count($ids) > $limit) {
                $ids = array_slice($ids, 0, $limit - $seen);
            }
            $callback($ids);
            $seen += count($ids);
            $last = end($ids);
        } while (count($ids) === $size && ($limit === null || $seen < $limit));

        return $seen;
    }

    /**
     * Freeze the current members of a segment. With a purpose key (e.g.
     * campaign:<id>) the snapshot is unique per purpose: a retry returns
     * the existing snapshot instead of creating a second one (unique index,
     * not just an application check).
     */
    public function snapshot(string $segmentId, Actor $actor, ?string $purposeKey = null): string
    {
        $tenantId = $this->context->requireTenant();
        if ($purposeKey !== null && ($existing = DB::table('segment_snapshots')->where('tenant_id', $tenantId)->where('purpose_key', $purposeKey)->value('id')) !== null) {
            return (string) $existing;
        }
        $definition = $this->definition($segmentId);
        $id = (string) Str::uuid7();

        $json = json_encode($definition, JSON_THROW_ON_ERROR);
        $q = $this->compiler->select($definition, $tenantId);
        // One statement: members and their count come from the same read,
        // and the snapshot row is never updated afterwards.
        DB::statement(
            "WITH m AS ({$q['sql']}),
                  snap AS (INSERT INTO segment_snapshots (id, tenant_id, segment_id, definition_hash, definition, member_count, created_by, created_at, purpose_key)
                           SELECT ?, ?, ?, ?, ?::jsonb, (SELECT count(*) FROM m), ?, now(), ? ON CONFLICT (tenant_id, purpose_key) DO NOTHING RETURNING id)
             INSERT INTO segment_snapshot_members (tenant_id, snapshot_id, customer_id) SELECT ?, snap.id, m.id FROM m CROSS JOIN snap",
            [...$q['bindings'], $id, $tenantId, $segmentId, hash('sha256', $json), $json, self::by($actor), $purposeKey, $tenantId],
        );
        $row = DB::table('segment_snapshots')->where('tenant_id', $tenantId)->where($purposeKey === null ? 'id' : 'purpose_key', $purposeKey ?? $id)->first(['id', 'member_count']);
        if ((string) $row->id === $id) {
            $this->audit->record(AuditAction::SegmentSnapshotCreated, 'segment_snapshot', $id, ['segment_id' => $segmentId, 'members' => (int) $row->member_count, 'purpose' => $purposeKey], $actor);
        }

        return (string) $row->id;
    }

    public function snapshotCount(string $snapshotId): int
    {
        return DB::table('segment_snapshot_members')->where('tenant_id', $this->context->requireTenant())->where('snapshot_id', $snapshotId)->count();
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
