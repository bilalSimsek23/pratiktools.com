<?php

namespace App\Segments;

/**
 * The only fields a segment rule may use, each with its value type and a
 * fixed SQL expression over customers c / customer_stats s. Expressions
 * are code, never input; values are always bound parameters.
 *
 * Types: int, money, datetime, bool, string, ids (uuid list), enum.
 */
final class SegmentFields
{
    public const OPERATORS = [
        'int' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
        'money' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
        'datetime' => ['within_last_days', 'before_days_ago', 'is_null', 'not_null'],
        'bool' => ['is'],
        'string' => ['eq', 'in'],
        'ids' => ['in'],
        'enum' => ['eq', 'in'],
    ];

    /**
     * Product of an order/cart item. Items written before their product was
     * synced keep only external ids (CatalogLinks links them on the next
     * write of the parent), so fall back to the external ids.
     */
    public static function itemProduct(string $alias): string
    {
        return "COALESCE({$alias}.product_id,
            (SELECT v.product_id FROM product_variants v WHERE v.tenant_id = {$alias}.tenant_id AND v.external_id = {$alias}.variant_external_id LIMIT 1),
            (SELECT p.id FROM products p WHERE p.tenant_id = {$alias}.tenant_id AND p.external_id = {$alias}.product_external_id LIMIT 1))";
    }

    /**
     * @return array<string, array{type: string, sql?: string, in?: string, values?: list<string>, cte?: string}>
     */
    public static function all(): array
    {
        // Relationship fields are semi-joins evaluated once ("c.id IN (…)"),
        // not per-customer correlated subqueries; tenant-scoped explicitly
        // and by RLS. Subqueries never return NULL (safe under NOT).
        $t = 'app_current_tenant()';
        $oiProduct = self::itemProduct('oi');
        $kiProduct = self::itemProduct('ki');
        $orders = "SELECT o.customer_id FROM orders o JOIN order_items oi ON oi.tenant_id = o.tenant_id AND oi.order_id = o.id
            WHERE o.tenant_id = {$t} AND o.customer_id IS NOT NULL AND o.counts_as_revenue";
        $openCarts = "SELECT k.customer_id FROM carts k JOIN cart_items ki ON ki.tenant_id = k.tenant_id AND ki.cart_id = k.id
            WHERE k.tenant_id = {$t} AND k.customer_id IS NOT NULL AND k.item_count > 0 AND k.last_seen_at >= now() - make_interval(mins => %CART_MINUTES%)";

        return [
            'order_count' => ['type' => 'int', 'sql' => 'COALESCE(s.order_count, 0)'],
            'total_revenue' => ['type' => 'money', 'sql' => 'COALESCE(s.lifetime_value, 0)'],
            'average_order_value' => ['type' => 'money', 'sql' => 'COALESCE(s.average_order_value, 0)'],
            'last_order_at' => ['type' => 'datetime', 'sql' => 's.last_order_at'],
            'first_order_at' => ['type' => 'datetime', 'sql' => 's.first_order_at'],
            'last_cart_at' => ['type' => 'datetime', 'sql' => 's.last_cart_at'],
            'member_since' => ['type' => 'datetime', 'sql' => 'c.member_since'],
            'created_at' => ['type' => 'datetime', 'sql' => 'c.created_at'],
            'last_interaction_at' => ['type' => 'datetime', 'sql' => '(SELECT max(ev.occurred_at) FROM customer_events ev WHERE ev.tenant_id = c.tenant_id AND ev.customer_id = c.id)'],
            'is_guest' => ['type' => 'bool', 'sql' => 'COALESCE(c.is_guest, false)'],
            'city' => ['type' => 'string', 'sql' => 'lower(c.city)'],
            'has_open_cart' => ['type' => 'bool', 'in' => "SELECT k.customer_id FROM carts k WHERE k.tenant_id = {$t} AND k.customer_id IS NOT NULL AND k.item_count > 0
                AND k.last_seen_at >= now() - make_interval(mins => %CART_MINUTES%)"],
            'newsletter_subscriber' => ['type' => 'bool', 'in' => "SELECT ci.customer_id FROM customer_identities ci WHERE ci.tenant_id = {$t} AND ci.type = 'newsletter_subscriber'"],
            'push_subscribed' => ['type' => 'bool', 'in' => "SELECT pp.customer_id FROM push_profiles pp JOIN push_subscriptions ps ON ps.tenant_id = pp.tenant_id AND ps.push_profile_id = pp.id
                WHERE pp.tenant_id = {$t} AND pp.customer_id IS NOT NULL AND ps.status = 'subscribed' AND ps.removed_at IS NULL"],
            'email_marketing_eligible' => ['type' => 'bool', 'cte' => 'email_eligible', 'in' => 'SELECT ee.customer_id FROM email_eligible ee WHERE ee.customer_id IS NOT NULL'],
            'email_consent_status' => ['type' => 'enum', 'values' => ['GRANTED', 'REVOKED', 'PENDING', 'UNKNOWN', 'NONE']],
            'purchased_category' => ['type' => 'ids', 'in' => str_replace('WHERE o.tenant_id', "JOIN product_categories pc ON pc.tenant_id = oi.tenant_id AND pc.product_id = {$oiProduct} WHERE o.tenant_id", $orders).' AND pc.category_id = ANY(?::uuid[])'],
            'purchased_product' => ['type' => 'ids', 'in' => "{$orders} AND {$oiProduct} = ANY(?::uuid[])"],
            'cart_contains_product' => ['type' => 'ids', 'in' => "{$openCarts} AND {$kiProduct} = ANY(?::uuid[])"],
            'cart_contains_category' => ['type' => 'ids', 'in' => str_replace('WHERE k.tenant_id', "JOIN product_categories pc ON pc.tenant_id = ki.tenant_id AND pc.product_id = {$kiProduct} WHERE k.tenant_id", $openCarts).' AND pc.category_id = ANY(?::uuid[])'],
        ];
    }

    /**
     * @return array{type: string, sql?: string, in?: string, values?: list<string>, cte?: string}|null
     */
    public static function get(string $name): ?array
    {
        $field = self::all()[$name] ?? null;
        if ($field === null) {
            return null;
        }
        $minutes = (int) config('automation.cart_seen_within_minutes');
        foreach (['sql', 'in'] as $k) {
            if (isset($field[$k])) {
                $field[$k] = str_replace('%CART_MINUTES%', (string) $minutes, $field[$k]);
            }
        }

        return $field;
    }
}
