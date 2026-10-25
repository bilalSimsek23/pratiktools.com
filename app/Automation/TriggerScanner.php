<?php

namespace App\Automation;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns new source rows into runs of published automations. Each source
 * is read after a per-tenant cursor (uuid7 ids are time ordered), in
 * bounded batches; the cursor moves only after the batch was handled, and
 * a re-read batch is harmless because run creation is idempotent.
 *
 * Never started: backfilled events, events older than
 * automation.max_trigger_age_hours or from before the version was
 * published, and subjects without a trusted customer (counted as
 * identity_blocked: anonymous carts, addresses shared by customers).
 */
final class TriggerScanner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RunFactory $runs,
        private readonly AutomationMetrics $metrics,
    ) {}

    /**
     * @return array<string, int> source => rows read
     */
    public function scan(): array
    {
        $automations = $this->published()->filter(fn ($a) => ! TriggerType::from($a->trigger['type'])->isTimeBased());
        $read = [];
        foreach (['customer_events', 'consent_events', 'push_subscriptions'] as $source) {
            $relevant = $automations->filter(fn ($a) => TriggerType::from($a->trigger['type'])->source() === $source)->values();
            $read[$source] = $this->scanSource($source, $relevant);
        }

        return $read;
    }

    /**
     * Published automations with their published version's trigger.
     *
     * @return Collection<int, \stdClass>
     */
    public function published(): Collection
    {
        return DB::table('automations as a')->join('automation_versions as v', fn ($j) => $j->on('v.id', '=', 'a.published_version_id')->on('v.tenant_id', '=', 'a.tenant_id'))
            ->where('a.tenant_id', $this->context->requireTenant())->where('a.status', AutomationStatus::Published->value)
            ->get(['a.id', 'a.published_version_id', 'a.last_evaluated_at', 'v.trigger', 'v.published_at'])
            ->map(function ($a) {
                $a->trigger = (array) json_decode($a->trigger, true);

                return $a;
            });
    }

    /**
     * @param  Collection<int, \stdClass>  $automations
     */
    private function scanSource(string $source, Collection $automations): int
    {
        $tenantId = $this->context->requireTenant();
        $cursor = DB::table('automation_trigger_cursors')->where('tenant_id', $tenantId)->where('source', $source)->value('position');
        $limit = (int) config('automation.tick.events_per_source');

        if ($automations->isEmpty()) {
            // Nobody listens: skip ahead so a later publish does not replay history.
            $this->move($source, $this->latest($source));

            return 0;
        }

        $rows = $this->rows($source, $cursor, $limit);
        foreach ($rows as $row) {
            foreach ($automations as $automation) {
                $this->handle($source, $row, $automation);
            }
        }
        if ($rows !== []) {
            $this->move($source, $this->position($source, end($rows)));
        }

        return count($rows);
    }

    private function handle(string $source, object $row, object $automation): void
    {
        $type = TriggerType::from($automation->trigger['type']);
        $params = (array) ($automation->trigger['params'] ?? []);
        $occurred = CarbonImmutable::parse($row->occurred_at);
        if (($row->is_backfill ?? false) || $occurred->lt(now()->subHours((int) config('automation.max_trigger_age_hours')))
            || ($automation->published_at !== null && $occurred->lt(CarbonImmutable::parse($automation->published_at)->subMinutes(5)))) {
            return;
        }

        $mapped = self::map($source, $row, $type, $params);
        if ($mapped === null) {
            return;
        }
        if ($mapped['customer_id'] === null) {
            $this->metrics->add($automation->id, ['identity_blocked' => 1]);

            return;
        }

        $this->runs->start($automation, $type, $mapped['ref'], $mapped['customer_id'], $mapped['scope'], $occurred, $mapped['context']);
    }

    /**
     * Source row → trigger (null when the row is not this trigger).
     *
     * @param  array<string, mixed>  $params
     * @return array{ref: string, customer_id: ?string, scope: string, context: array<string, mixed>}|null
     */
    public static function map(string $source, object $row, TriggerType $type, array $params): ?array
    {
        $props = is_string($row->properties ?? null) ? (array) json_decode($row->properties, true) : (array) ($row->properties ?? []);
        $customer = $row->customer_id ?? null;
        $scope = 'customer:'.$customer;

        return match (true) {
            $type === TriggerType::CartAbandoned && $row->name === 'CART_UPDATED' && (int) ($props['item_count'] ?? 0) > 0 => [
                'ref' => (string) $row->source_event_id, 'customer_id' => $customer, 'scope' => 'cart:'.$props['cart_id'],
                'context' => ['cart_id' => $props['cart_id'], 'cart_hash' => self::cartHash((string) $row->source_event_id)],
            ],
            $type === TriggerType::OrderCreated && $row->name === 'ORDER_PLACED' => [
                'ref' => (string) $row->source_event_id, 'customer_id' => $customer, 'scope' => $scope, 'context' => ['order_id' => $props['order_id'] ?? null],
            ],
            $type === TriggerType::OrderCompleted && $row->name === 'ORDER_STATUS_CHANGED'
                && in_array((int) ($props['to_status'] ?? -1), array_map('intval', (array) config('automation.completed_order_status_codes')), true) => [
                    'ref' => (string) $row->source_event_id, 'customer_id' => $customer, 'scope' => $scope, 'context' => ['order_id' => $props['order_id'] ?? null],
                ],
            $type === TriggerType::CustomerCreated && $row->name === 'CUSTOMER_CREATED' && ($props['source_kind'] ?? null) === 'member',
            $type === TriggerType::NewsletterSubscribed && $row->name === 'CUSTOMER_CREATED' && ($props['source_kind'] ?? null) === 'subscriber' => [
                'ref' => (string) $row->source_event_id, 'customer_id' => $customer, 'scope' => $scope, 'context' => [],
            ],
            $type === TriggerType::ConsentChanged && $source === 'consent_events'
                && (! isset($params['channel']) || $params['channel'] === $row->channel) && (! isset($params['new_status']) || $params['new_status'] === $row->new_status) => [
                    'ref' => 'consent:'.$row->id, 'customer_id' => $customer, 'scope' => $scope, 'context' => ['channel' => $row->channel, 'new_status' => $row->new_status],
                ],
            $type === TriggerType::PushSubscriptionChanged && $source === 'push_subscriptions' => [
                'ref' => 'push:'.$row->id.':'.$row->status.':'.$row->occurred_at, 'customer_id' => $customer, 'scope' => $scope, 'context' => ['status' => $row->status],
            ],
            default => null,
        };
    }

    /**
     * Cart events are "cart:{external id}:{content hash}".
     */
    public static function cartHash(string $sourceEventId): ?string
    {
        $parts = explode(':', $sourceEventId);

        return count($parts) >= 3 ? end($parts) : null;
    }

    /**
     * @return list<object>
     */
    public function rows(string $source, ?string $cursor, int $limit, ?CarbonImmutable $since = null): array
    {
        $tenantId = $this->context->requireTenant();

        return match ($source) {
            'customer_events' => DB::select(
                "SELECT id, name, customer_id, occurred_at, source_event_id, is_backfill, properties FROM customer_events
                 WHERE tenant_id = ? AND id > ? AND name IN ('CART_UPDATED', 'ORDER_PLACED', 'ORDER_STATUS_CHANGED', 'CUSTOMER_CREATED')
                   AND (?::timestamptz IS NULL OR occurred_at >= ?::timestamptz) ORDER BY id LIMIT ?",
                [$tenantId, $cursor ?? '00000000-0000-0000-0000-000000000000', $since, $since, $limit]),
            // A consent movement starts a run only for an address with exactly one customer.
            'consent_events' => DB::select(
                'SELECT e.id, e.channel, e.new_status, e.occurred_at, e.is_backfill,
                        (SELECT CASE WHEN count(*) = 1 THEN min(rc.customer_id::text)::uuid END FROM recipient_customers rc WHERE rc.tenant_id = e.tenant_id AND rc.recipient_id = e.recipient_id) AS customer_id
                 FROM consent_events e WHERE e.tenant_id = ? AND e.id > ? AND (?::timestamptz IS NULL OR e.occurred_at >= ?::timestamptz) ORDER BY e.id LIMIT ?',
                [$tenantId, $cursor ?? '00000000-0000-0000-0000-000000000000', $since, $since, $limit]),
            'push_subscriptions' => DB::select(
                "SELECT s.id, s.status, s.updated_at AS occurred_at, false AS is_backfill,
                        CASE WHEN p.identity_status = 'resolved' THEN p.customer_id END AS customer_id
                 FROM push_subscriptions s LEFT JOIN push_profiles p ON p.tenant_id = s.tenant_id AND p.id = s.push_profile_id
                 WHERE s.tenant_id = ? AND (s.updated_at, s.id) > (?::timestamptz, ?::uuid) AND (?::timestamptz IS NULL OR s.updated_at >= ?::timestamptz)
                 ORDER BY s.updated_at, s.id LIMIT ?",
                [$tenantId, ...self::pushCursor($cursor), $since, $since, $limit]),
            default => throw new \InvalidArgumentException('Unknown trigger source.'),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function pushCursor(?string $cursor): array
    {
        return $cursor !== null && str_contains($cursor, '|') ? explode('|', $cursor, 2) : ['1970-01-01T00:00:00Z', '00000000-0000-0000-0000-000000000000'];
    }

    private function position(string $source, object $row): string
    {
        return $source === 'push_subscriptions' ? CarbonImmutable::parse($row->occurred_at)->format('Y-m-d\TH:i:s.uP').'|'.$row->id : (string) $row->id;
    }

    private function latest(string $source): ?string
    {
        $tenantId = $this->context->requireTenant();
        if ($source === 'push_subscriptions') {
            $row = DB::table('push_subscriptions')->where('tenant_id', $tenantId)->orderByDesc('updated_at')->orderByDesc('id')->first(['id', 'updated_at']);

            return $row === null ? null : CarbonImmutable::parse($row->updated_at)->format('Y-m-d\TH:i:s.uP').'|'.$row->id;
        }

        $id = DB::table($source)->where('tenant_id', $tenantId)->orderByDesc('id')->value('id');

        return $id === null ? null : (string) $id;
    }

    private function move(string $source, ?string $position): void
    {
        if ($position === null) {
            return;
        }
        DB::statement(
            'INSERT INTO automation_trigger_cursors (tenant_id, source, position, updated_at) VALUES (?, ?, ?, now())
             ON CONFLICT (tenant_id, source) DO UPDATE SET position = EXCLUDED.position, updated_at = now()',
            [$this->context->requireTenant(), $source, $position],
        );
    }
}
