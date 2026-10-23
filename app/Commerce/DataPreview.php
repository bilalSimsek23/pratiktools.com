<?php

namespace App\Commerce;

use Illuminate\Support\Facades\DB;

/**
 * A small sample of synced data so a tenant can check the mapping. At most
 * ten records per type; e-mail addresses and phone numbers are masked.
 */
final class DataPreview
{
    public const LIMIT = 10;

    /**
     * @return array{customers: list<array<string, mixed>>, products: list<array<string, mixed>>, orders: list<array<string, mixed>>}
     */
    public function sample(int $limit = self::LIMIT): array
    {
        $limit = max(1, min(self::LIMIT, $limit));

        $customers = DB::table('customers as c')
            ->leftJoin('customer_identities as m', fn ($j) => $j->on('m.customer_id', '=', 'c.id')->where('m.type', 'commerce_member'))
            ->leftJoin('customer_stats as s', 's.customer_id', '=', 'c.id')
            ->orderByDesc('c.updated_at')
            ->limit($limit)
            ->get(['c.id', 'm.value as member_id', 'c.email', 'c.phone', 'c.first_name', 'c.city', 'c.is_guest', 'c.is_active', 's.order_count', 's.lifetime_value', 's.last_order_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'member_id' => $c->member_id,
                'email' => self::maskEmail($c->email),
                'phone' => self::maskPhone($c->phone),
                'first_name' => $c->first_name === null ? null : mb_substr($c->first_name, 0, 1).'***',
                'city' => $c->city,
                'is_guest' => (bool) $c->is_guest,
                'is_active' => $c->is_active === null ? null : (bool) $c->is_active,
                'order_count' => (int) ($c->order_count ?? 0),
                'lifetime_value' => $c->lifetime_value,
                'last_order_at' => $c->last_order_at,
            ])->all();

        $products = DB::table('products as p')
            ->orderByDesc('p.updated_at')
            ->limit($limit)
            ->get(['p.id', 'p.external_id', 'p.name', 'p.brand', 'p.is_active', 'p.total_stock', 'p.url'])
            ->map(fn ($p) => (array) $p + [
                'variants' => DB::table('product_variants')->where('product_id', $p->id)->count(),
                'categories' => DB::table('product_categories')->where('product_id', $p->id)->count(),
            ])->all();

        $orders = DB::table('orders')
            ->orderByDesc('placed_at')
            ->limit($limit)
            ->get(['id', 'external_id', 'order_number', 'customer_id', 'email', 'channel', 'source', 'is_marketplace', 'status_label', 'counts_as_revenue', 'placed_at', 'currency', 'net_total', 'grand_total'])
            ->map(fn ($o) => ['email' => self::maskEmail($o->email)] + (array) $o + [
                'items' => DB::table('order_items')->where('order_id', $o->id)->count(),
            ])->all();

        return ['customers' => array_values($customers), 'products' => array_values($products), 'orders' => array_values($orders)];
    }

    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email === null ? null : '***';
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public static function maskPhone(?string $phone): ?string
    {
        return $phone === null ? null : substr($phone, 0, 3).str_repeat('*', max(0, strlen($phone) - 5)).substr($phone, -2);
    }
}
