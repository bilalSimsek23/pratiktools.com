<?php

namespace App\Commerce\Sync;

use App\Commerce\Data\CategoryRecord;
use App\Commerce\Data\ProductRecord;
use App\Commerce\Data\VariantRecord;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Categories, products and variants. Products are compared by a hash of
 * everything we store (variants and category links included), so an
 * unchanged product costs one lookup. A variant that disappears from its
 * product card is deactivated, never deleted: order and cart items keep
 * pointing at it.
 */
final class CatalogUpserter
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<CategoryRecord>  $records
     */
    public function categories(array $records, UpsertResult $result): void
    {
        $tenantId = $this->context->requireTenant();

        DB::transaction(function () use ($records, $tenantId, $result) {
            foreach ($records as $c) {
                $row = DB::selectOne(
                    'INSERT INTO categories (id, tenant_id, external_id, parent_external_id, name, code, url, is_active, sort, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())
                     ON CONFLICT (tenant_id, external_id) DO UPDATE SET
                        parent_external_id = EXCLUDED.parent_external_id, name = EXCLUDED.name, code = EXCLUDED.code,
                        url = EXCLUDED.url, is_active = EXCLUDED.is_active, sort = EXCLUDED.sort, updated_at = now()
                     WHERE (categories.parent_external_id, categories.name, categories.code, categories.url, categories.is_active, categories.sort)
                        IS DISTINCT FROM (EXCLUDED.parent_external_id, EXCLUDED.name, EXCLUDED.code, EXCLUDED.url, EXCLUDED.is_active, EXCLUDED.sort)
                     RETURNING (xmax = 0) AS inserted',
                    [(string) Str::uuid7(), $tenantId, $c->externalId, $c->parentExternalId, $c->name, $c->code, $c->url, $c->isActive, $c->sort],
                );
                $row === null ? $result->skipped++ : ($row->inserted ? $result->inserted++ : $result->updated++);
            }

            // Parents are linked after the whole page is stored, so the
            // order categories arrive in does not matter.
            DB::update(
                'UPDATE categories c SET parent_id = p.id
                 FROM categories p
                 WHERE c.tenant_id = ? AND p.tenant_id = c.tenant_id AND p.external_id = c.parent_external_id
                   AND c.parent_id IS DISTINCT FROM p.id',
                [$tenantId],
            );
            DB::update('UPDATE categories SET parent_id = NULL WHERE tenant_id = ? AND parent_external_id IS NULL AND parent_id IS NOT NULL', [$tenantId]);
            DB::update(
                'UPDATE product_categories pc SET category_id = c.id
                 FROM categories c
                 WHERE pc.tenant_id = ? AND c.tenant_id = pc.tenant_id AND c.external_id = pc.category_external_id AND pc.category_id IS NULL',
                [$tenantId],
            );
        });
    }

    /**
     * @param  list<ProductRecord>  $records
     */
    public function products(array $records, UpsertResult $result): void
    {
        if ($records === []) {
            return;
        }

        $tenantId = $this->context->requireTenant();
        $stored = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->whereIn('external_id', array_map(fn ($p) => $p->externalId, $records))
            ->selectRaw("external_id, id, attributes->>'source_hash' AS source_hash")
            ->get()
            ->keyBy('external_id');

        DB::transaction(function () use ($records, $stored, $tenantId, $result) {
            foreach ($records as $p) {
                $hash = $this->productHash($p);
                $existing = $stored[$p->externalId] ?? null;

                if ($existing !== null && $existing->source_hash === $hash) {
                    $result->skipped++;

                    continue;
                }

                $productId = $this->upsertProduct($p, $existing?->id, $hash, $tenantId);
                $existing === null ? $result->inserted++ : $result->updated++;

                $this->syncVariants($productId, $p->variants, $tenantId);
                $this->syncCategories($productId, $p, $tenantId);
            }
        });
    }

    private function upsertProduct(ProductRecord $p, ?string $id, string $hash, string $tenantId): string
    {
        $values = [
            'name' => $p->name,
            'brand' => $p->brand,
            'brand_external_id' => $p->brandExternalId,
            'main_category_external_id' => $p->mainCategoryExternalId,
            'url' => $p->url,
            'image_url' => $p->imageUrl,
            'is_active' => $p->isActive,
            'total_stock' => $p->totalStock,
            'source_created_at' => $p->createdAt,
            'attributes' => json_encode($p->attributes + ['source_hash' => $hash]),
            'updated_at' => now(),
        ];

        if ($id !== null) {
            DB::table('products')->where('tenant_id', $tenantId)->where('id', $id)->update($values);

            return $id;
        }

        $id = (string) Str::uuid7();
        DB::table('products')->insert($values + ['id' => $id, 'tenant_id' => $tenantId, 'external_id' => $p->externalId, 'created_at' => now()]);

        return $id;
    }

    /**
     * @param  list<VariantRecord>  $variants
     */
    private function syncVariants(string $productId, array $variants, string $tenantId): void
    {
        foreach ($variants as $v) {
            DB::statement(
                'INSERT INTO product_variants (id, tenant_id, product_id, external_id, sku, barcode, price, sale_price, currency, stock, is_active, color, size, image_url, options, stock_updated_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, now(), now())
                 ON CONFLICT (tenant_id, external_id) DO UPDATE SET
                    product_id = EXCLUDED.product_id, sku = EXCLUDED.sku, barcode = EXCLUDED.barcode, price = EXCLUDED.price,
                    sale_price = EXCLUDED.sale_price, currency = EXCLUDED.currency, stock = EXCLUDED.stock, is_active = EXCLUDED.is_active,
                    color = EXCLUDED.color, size = EXCLUDED.size, image_url = EXCLUDED.image_url, options = EXCLUDED.options,
                    stock_updated_at = EXCLUDED.stock_updated_at, updated_at = now()',
                [
                    (string) Str::uuid7(), $tenantId, $productId, $v->externalId, $v->sku, $v->barcode, $v->price, $v->salePrice,
                    $v->currency, $v->stock, $v->isActive, $v->color, $v->size, $v->imageUrl, json_encode($v->options), $v->stockUpdatedAt,
                ],
            );
        }

        DB::table('product_variants')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereNotIn('external_id', array_map(fn ($v) => $v->externalId, $variants) ?: [''])
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function syncCategories(string $productId, ProductRecord $p, string $tenantId): void
    {
        $externalIds = array_values(array_unique($p->categoryExternalIds));

        DB::table('product_categories')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereNotIn('category_external_id', $externalIds ?: [''])
            ->delete();

        foreach ($externalIds as $categoryExternalId) {
            DB::statement(
                'INSERT INTO product_categories (tenant_id, product_id, category_external_id, category_id)
                 VALUES (?, ?, ?, (SELECT id FROM categories WHERE tenant_id = ? AND external_id = ?))
                 ON CONFLICT (tenant_id, product_id, category_external_id) DO UPDATE SET category_id = EXCLUDED.category_id',
                [$tenantId, $productId, $categoryExternalId, $tenantId, $categoryExternalId],
            );
        }
    }

    private function productHash(ProductRecord $p): string
    {
        return substr(hash('sha256', (string) json_encode($p)), 0, 16);
    }
}
