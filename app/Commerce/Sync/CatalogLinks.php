<?php

namespace App\Commerce\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Resolves product and variant ids of freshly written order or cart items
 * in one pass. Items whose product has not been synced keep only the
 * external ids; the next write of the parent links them.
 */
final class CatalogLinks
{
    /**
     * @param  'order_items'|'cart_items'  $table
     * @param  'order_id'|'cart_id'  $parentColumn
     * @param  list<string>  $parentIds
     */
    public static function resolve(string $table, string $parentColumn, array $parentIds, string $tenantId): void
    {
        if ($parentIds === []) {
            return;
        }

        $ids = '{'.implode(',', $parentIds).'}';

        DB::update(
            "UPDATE {$table} i SET variant_id = v.id, product_id = v.product_id
             FROM product_variants v
             WHERE i.tenant_id = ? AND i.{$parentColumn} = ANY(?::uuid[]) AND v.tenant_id = i.tenant_id AND v.external_id = i.variant_external_id",
            [$tenantId, $ids],
        );
        DB::update(
            "UPDATE {$table} i SET product_id = p.id
             FROM products p
             WHERE i.tenant_id = ? AND i.{$parentColumn} = ANY(?::uuid[]) AND i.product_id IS NULL AND p.tenant_id = i.tenant_id AND p.external_id = i.product_external_id",
            [$tenantId, $ids],
        );
    }
}
