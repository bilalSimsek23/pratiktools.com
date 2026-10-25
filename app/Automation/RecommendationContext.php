<?php

namespace App\Automation;

use App\Segments\SegmentFields;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic recommendation references (ids only) for an intent:
 * categories the customer bought from, categories in the cart, the most
 * recent order's categories, and the tenant's current bestsellers as a
 * fallback. No scoring model; the dispatcher renders whatever is still
 * active and in stock at send time.
 */
final class RecommendationContext
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{purchased_categories: list<string>, cart_categories: list<string>, recent_order_categories: list<string>, bestsellers: list<string>}
     */
    public function forCustomer(string $customerId, ?string $cartId = null): array
    {
        $tenantId = $this->context->requireTenant();
        $ids = fn (array $rows) => array_values(array_map(fn ($r) => (string) $r->id, $rows));

        $purchased = DB::select(
            'SELECT pc.category_id AS id FROM orders o JOIN order_items oi ON oi.tenant_id = o.tenant_id AND oi.order_id = o.id
             JOIN product_categories pc ON pc.tenant_id = oi.tenant_id AND pc.product_id = oi.product_id
             WHERE o.tenant_id = ? AND o.customer_id = ? AND o.counts_as_revenue AND pc.category_id IS NOT NULL
             GROUP BY pc.category_id ORDER BY count(*) DESC, pc.category_id LIMIT 5', [$tenantId, $customerId]);
        $recent = DB::select(
            'SELECT DISTINCT pc.category_id AS id FROM order_items oi JOIN product_categories pc ON pc.tenant_id = oi.tenant_id AND pc.product_id = oi.product_id
             WHERE oi.tenant_id = ? AND pc.category_id IS NOT NULL AND oi.order_id = (SELECT o.id FROM orders o WHERE o.tenant_id = ? AND o.customer_id = ? ORDER BY o.placed_at DESC LIMIT 1)
             ORDER BY 1 LIMIT 5', [$tenantId, $tenantId, $customerId]);
        $cart = $cartId === null ? [] : DB::select(
            'SELECT DISTINCT pc.category_id AS id FROM cart_items ki JOIN product_categories pc ON pc.tenant_id = ki.tenant_id AND pc.product_id = '.SegmentFields::itemProduct('ki').'
             WHERE ki.tenant_id = ? AND ki.cart_id = ? AND pc.category_id IS NOT NULL ORDER BY 1 LIMIT 5', [$tenantId, $cartId]);
        $best = DB::select(
            'SELECT oi.product_id AS id FROM order_items oi JOIN orders o ON o.tenant_id = oi.tenant_id AND o.id = oi.order_id
             JOIN products p ON p.tenant_id = oi.tenant_id AND p.id = oi.product_id
             WHERE oi.tenant_id = ? AND o.placed_at >= now() - interval \'30 days\' AND o.counts_as_revenue AND p.is_active AND p.total_stock > 0
             GROUP BY oi.product_id ORDER BY sum(oi.quantity) DESC, oi.product_id LIMIT 8', [$tenantId]);

        return ['purchased_categories' => $ids($purchased), 'cart_categories' => $ids($cart), 'recent_order_categories' => $ids($recent), 'bestsellers' => $ids($best)];
    }
}
