<?php

namespace App\Commerce\Sync;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Derived per-customer commerce stats, recomputed from stored orders and
 * carts (never incremented), so a rebuild always converges.
 *
 * Only store orders that count as revenue are included: cancelled and
 * returned orders are excluded, and marketplace orders are excluded because
 * the marketplace, not the store, owns that customer relationship. Lifetime
 * value is the gross amount paid (grand_total).
 */
final class CustomerStatsBuilder
{
    private const SQL = <<<'SQL'
        INSERT INTO customer_stats (customer_id, tenant_id, order_count, lifetime_value, average_order_value, currency, first_order_at, last_order_at, last_cart_at, computed_at)
        SELECT c.id, c.tenant_id,
               coalesce(o.n, 0), coalesce(o.ltv, 0),
               CASE WHEN coalesce(o.n, 0) > 0 THEN round(o.ltv / o.n, 2) ELSE 0 END,
               o.currency, o.first_at, o.last_at, ct.last_cart_at, now()
        FROM customers c
        LEFT JOIN LATERAL (
            SELECT count(*) AS n, sum(grand_total) AS ltv, min(placed_at) AS first_at, max(placed_at) AS last_at, max(currency) AS currency
            FROM orders
            WHERE tenant_id = c.tenant_id AND customer_id = c.id AND counts_as_revenue AND NOT is_marketplace
        ) o ON true
        LEFT JOIN LATERAL (
            SELECT max(source_created_at) AS last_cart_at FROM carts WHERE tenant_id = c.tenant_id AND customer_id = c.id
        ) ct ON true
        WHERE c.tenant_id = ? AND %s
        ON CONFLICT (customer_id) DO UPDATE SET
            order_count = EXCLUDED.order_count, lifetime_value = EXCLUDED.lifetime_value,
            average_order_value = EXCLUDED.average_order_value, currency = EXCLUDED.currency,
            first_order_at = EXCLUDED.first_order_at, last_order_at = EXCLUDED.last_order_at,
            last_cart_at = EXCLUDED.last_cart_at, computed_at = now()
        SQL;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $customerIds
     */
    public function rebuild(array $customerIds): int
    {
        $total = 0;

        foreach (array_chunk(array_values(array_unique($customerIds)), 1000) as $chunk) {
            $total += DB::affectingStatement(sprintf(self::SQL, 'c.id = ANY(?::uuid[])'), [$this->context->requireTenant(), '{'.implode(',', $chunk).'}']);
        }

        return $total;
    }

    /**
     * Every customer of the tenant, in id order and bounded batches.
     */
    public function rebuildAll(int $batchSize = 5000): int
    {
        $tenantId = $this->context->requireTenant();
        $after = '00000000-0000-0000-0000-000000000000';
        $total = 0;

        while (true) {
            $ids = DB::table('customers')->where('tenant_id', $tenantId)->where('id', '>', $after)->orderBy('id')->limit($batchSize)->pluck('id')->all();

            if ($ids === []) {
                return $total;
            }

            $total += $this->rebuild($ids);
            $after = end($ids);
        }
    }
}
