<?php

namespace App\Commerce\Sync;

use App\Commerce\Identity\ContactNormalizer;
use App\Segments\ReadOnlyTransaction;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Repairs local relations that the sync could not set because the parent
 * was written before its reference existed (carts synced before products
 * or members). Local database only: no call to Ticimax or any provider.
 *
 * 1. Catalog: cart/order items without product_id get variant/product by
 *    exact external id (same rule as CatalogLinks).
 * 2. Cart customers: by the Ticimax member id identity first; else by the
 *    normalized e-mail identity, only for non-marketplace addresses with
 *    no identity merge under review. Never by phone, never a merge.
 *
 * Idempotent (only NULL columns are filled), bounded (keyset chunks) and
 * with a dry-run that counts inside a READ ONLY transaction.
 */
final class CommerceRelink
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ContactNormalizer $normalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $tenantId = $this->context->requireTenant();
        $items = fn (string $table) => DB::selectOne(
            "SELECT count(*) AS total, count(*) FILTER (WHERE i.product_id IS NOT NULL) AS linked,
                count(*) FILTER (WHERE i.product_id IS NULL AND EXISTS (SELECT 1 FROM product_variants v WHERE v.tenant_id = i.tenant_id AND v.external_id = i.variant_external_id)) AS by_variant,
                count(*) FILTER (WHERE i.product_id IS NULL AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.tenant_id = i.tenant_id AND v.external_id = i.variant_external_id)
                    AND EXISTS (SELECT 1 FROM products p WHERE p.tenant_id = i.tenant_id AND p.external_id = i.product_external_id)) AS by_product
             FROM {$table} i WHERE i.tenant_id = ?", [$tenantId]);

        $carts = DB::selectOne(
            "SELECT count(*) AS total, count(*) FILTER (WHERE k.customer_id IS NOT NULL) AS linked,
                count(*) FILTER (WHERE k.customer_id IS NULL AND COALESCE(k.external_member_id, '') IN ('', '0') AND COALESCE(k.email, '') = '') AS anonymous,
                count(*) FILTER (WHERE COALESCE(k.email, '') <> '') AS with_email,
                count(*) FILTER (WHERE COALESCE(k.external_member_id, '') NOT IN ('', '0')) AS with_member_id,
                count(*) FILTER (WHERE k.customer_id IS NULL AND EXISTS (SELECT 1 FROM customer_identities ci WHERE ci.tenant_id = k.tenant_id AND ci.type = 'commerce_member' AND ci.value = k.external_member_id)) AS member_recoverable
             FROM carts k WHERE k.tenant_id = ?", [$tenantId]);

        [$emailRecoverable, $collisions, $marketplace] = $this->emailCandidates(countOnly: true);
        $unresolvedWithIdentity = (int) DB::selectOne(
            "SELECT count(*) AS n FROM carts k WHERE k.tenant_id = ? AND k.customer_id IS NULL AND (COALESCE(k.external_member_id, '') NOT IN ('', '0') OR COALESCE(k.email, '') <> '')",
            [$tenantId])->n;

        return [
            'cart_items' => array_map('intval', (array) $items('cart_items')),
            'order_items' => array_map('intval', (array) $items('order_items')),
            'carts' => array_map('intval', (array) $carts) + [
                'email_recoverable' => $emailRecoverable,
                'email_collision' => $collisions,
                'email_marketplace' => $marketplace,
                'unresolved_with_identity' => max(0, $unresolvedWithIdentity - (int) $carts->member_recoverable - $emailRecoverable),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        return ReadOnlyTransaction::run(fn (): array => $this->report());
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>, updated: array<string, int>}
     */
    public function run(int $chunk = 1000): array
    {
        $before = $this->report();
        $updated = ['cart_items' => 0, 'order_items' => 0, 'carts_by_member' => 0, 'carts_by_email' => 0];

        foreach (['cart_items', 'order_items'] as $table) {
            $updated[$table] = $this->relinkItems($table, $chunk);
        }
        $updated['carts_by_member'] = $this->chunked(fn (string $last) => DB::update(
            "UPDATE carts k SET customer_id = ci.customer_id, updated_at = now() FROM customer_identities ci
             WHERE k.id IN (SELECT k2.id FROM carts k2 WHERE k2.tenant_id = ? AND k2.customer_id IS NULL AND k2.id > ? AND COALESCE(k2.external_member_id, '') NOT IN ('', '0') ORDER BY k2.id LIMIT ?)
               AND ci.tenant_id = k.tenant_id AND ci.type = 'commerce_member' AND ci.value = k.external_member_id AND k.customer_id IS NULL",
            [$this->context->requireTenant(), $last, $chunk]), fn (string $last) => $this->nextCart($last, $chunk, member: true));
        foreach (array_chunk($this->emailCandidates(countOnly: false)[0], $chunk, true) as $batch) {
            foreach ($batch as $cartId => $customerId) {
                $updated['carts_by_email'] += DB::update('UPDATE carts SET customer_id = ?, updated_at = now() WHERE tenant_id = ? AND id = ? AND customer_id IS NULL',
                    [$customerId, $this->context->requireTenant(), $cartId]);
            }
        }

        return ['before' => $before, 'after' => $this->report(), 'updated' => $updated];
    }

    private function relinkItems(string $table, int $chunk): int
    {
        $tenantId = $this->context->requireTenant();
        $total = 0;
        $last = '00000000-0000-0000-0000-000000000000';
        while (true) {
            $ids = DB::table($table)->where('tenant_id', $tenantId)->whereNull('product_id')->where('id', '>', $last)->orderBy('id')->limit($chunk)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $set = '{'.implode(',', $ids).'}';
            $total += DB::update("UPDATE {$table} i SET variant_id = v.id, product_id = v.product_id FROM product_variants v
                WHERE i.tenant_id = ? AND i.id = ANY(?::uuid[]) AND i.product_id IS NULL AND v.tenant_id = i.tenant_id AND v.external_id = i.variant_external_id", [$tenantId, $set]);
            $total += DB::update("UPDATE {$table} i SET product_id = p.id FROM products p
                WHERE i.tenant_id = ? AND i.id = ANY(?::uuid[]) AND i.product_id IS NULL AND p.tenant_id = i.tenant_id AND p.external_id = i.product_external_id", [$tenantId, $set]);
            $last = (string) end($ids);
        }

        return $total;
    }

    /**
     * cart id => customer id for safely linkable e-mail carts, plus counts
     * of collisions and marketplace addresses.
     *
     * @return array{0: array<string, string>|int, 1: int, 2: int}
     */
    private function emailCandidates(bool $countOnly): array
    {
        $tenantId = $this->context->requireTenant();
        $rows = DB::table('carts')->where('tenant_id', $tenantId)->whereNull('customer_id')->where('email', '<>', '')->whereNotNull('email')
            ->where(fn ($q) => $q->whereNull('external_member_id')->orWhereIn('external_member_id', ['', '0'])
                ->orWhereNotExists(fn ($s) => $s->from('customer_identities as ci')->whereColumn('ci.tenant_id', 'carts.tenant_id')->where('ci.type', 'commerce_member')->whereColumn('ci.value', 'carts.external_member_id')))
            ->get(['id', 'email']);
        $links = [];
        $collisions = 0;
        $marketplace = 0;
        foreach ($rows as $r) {
            $email = $this->normalizer->email($r->email);
            if ($email === null) {
                continue;
            }
            if ($this->normalizer->isMarketplaceEmail($email)) {
                $marketplace++;

                continue;
            }
            $owner = DB::table('customer_identities')->where('tenant_id', $tenantId)->where('type', 'email')->where('value', $email)->value('customer_id');
            if ($owner === null) {
                continue;
            }
            $conflict = DB::table('customer_merges')->where('tenant_id', $tenantId)->where('identity_type', 'email')->where('identity_hash', hash('sha256', $email))
                ->where('status', 'pending_review')->exists();
            if ($conflict) {
                $collisions++;

                continue;
            }
            $links[(string) $r->id] = (string) $owner;
        }

        return [$countOnly ? count($links) : $links, $collisions, $marketplace];
    }

    /**
     * @param  \Closure(string): int  $update
     * @param  \Closure(string): ?string  $next
     */
    private function chunked(\Closure $update, \Closure $next): int
    {
        $total = 0;
        $last = '00000000-0000-0000-0000-000000000000';
        while (($boundary = $next($last)) !== null) {
            $total += $update($last);
            $last = $boundary;
        }

        return $total;
    }

    private function nextCart(string $last, int $chunk, bool $member): ?string
    {
        $ids = DB::table('carts')->where('tenant_id', $this->context->requireTenant())->whereNull('customer_id')->where('id', '>', $last)
            ->when($member, fn ($q) => $q->whereNotNull('external_member_id')->whereNotIn('external_member_id', ['', '0']))->orderBy('id')->limit($chunk)->pluck('id')->all();

        return $ids === [] ? null : (string) end($ids);
    }
}
