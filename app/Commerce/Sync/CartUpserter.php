<?php

namespace App\Commerce\Sync;

use App\Commerce\Data\CartItemRecord;
use App\Commerce\Data\CartRecord;
use App\Commerce\Identity\ContactNormalizer;
use App\Commerce\Identity\CustomerIdentityService as Identities;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carts and their items. Ticimax carts carry only a creation date, so a
 * change is detected by a hash of the cart content. A changed cart emits
 * CART_UPDATED; deciding that a cart was abandoned is the automation
 * engine's job (F5), not the sync's.
 *
 * Carts never create customers or identities: they link to an existing
 * member, or to an existing customer by e-mail.
 */
final class CartUpserter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Identities $identities,
        private readonly ContactNormalizer $normalizer,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  list<CartRecord>  $records
     */
    public function upsert(array $records, string $source, bool $backfill, UpsertResult $result): void
    {
        if ($records === []) {
            return;
        }

        $tenantId = $this->context->requireTenant();
        $stored = DB::table('carts')
            ->where('tenant_id', $tenantId)
            ->whereIn('external_id', array_map(fn ($c) => $c->externalId, $records))
            ->get(['external_id', 'id', 'customer_id', 'content_hash'])
            ->keyBy('external_id');

        DB::transaction(function () use ($records, $stored, $tenantId, $source, $backfill, $result) {
            $changedCartIds = [];

            foreach ($records as $c) {
                $hash = $this->contentHash($c);
                $existing = $stored[$c->externalId] ?? null;

                if ($existing !== null && $existing->content_hash === $hash) {
                    DB::table('carts')->where('tenant_id', $tenantId)->where('id', $existing->id)->update(['last_seen_at' => now()]);
                    $result->skipped++;

                    continue;
                }

                $customerId = $this->resolveCustomer($c);
                $total = round(array_sum(array_map(fn (CartItemRecord $i) => $i->quantity * ($i->unitPriceWithTax ?? $i->unitPrice ?? 0), $c->items)), 4);
                $itemCount = (int) array_sum(array_map(fn (CartItemRecord $i) => $i->quantity, $c->items));

                $values = [
                    'customer_id' => $customerId,
                    'external_member_id' => $c->memberExternalId,
                    'email' => $c->email,
                    'currency' => $c->currency,
                    'item_count' => $itemCount,
                    'total' => $total,
                    'content_hash' => $hash,
                    'source_created_at' => $c->createdAt,
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ];

                if ($existing !== null) {
                    $cartId = $existing->id;
                    DB::table('carts')->where('tenant_id', $tenantId)->where('id', $cartId)->update($values);
                    $result->updated++;
                } else {
                    $cartId = (string) Str::uuid7();
                    DB::table('carts')->insert($values + ['id' => $cartId, 'tenant_id' => $tenantId, 'external_id' => $c->externalId, 'created_at' => now()]);
                    $result->inserted++;
                }

                $this->replaceItems($cartId, $c->items, $tenantId);
                $changedCartIds[] = $cartId;
                $result->touch($customerId);
                $result->touch($existing?->customer_id);

                $this->events->record(CustomerEventName::CartUpdated, $customerId, CarbonImmutable::now(), $source, "cart:{$c->externalId}:{$hash}", [
                    'cart_id' => $cartId,
                    'item_count' => $itemCount,
                    'total' => $total,
                    'currency' => $c->currency,
                    'cart_created_at' => $c->createdAt->toIso8601String(),
                ], $backfill);
            }

            CatalogLinks::resolve('cart_items', 'cart_id', $changedCartIds, $tenantId);
        });
    }

    private function resolveCustomer(CartRecord $c): ?string
    {
        if ($c->memberExternalId !== null && ($id = $this->identities->find(Identities::MEMBER, $c->memberExternalId)) !== null) {
            return $id;
        }

        $email = $this->normalizer->email($c->email);

        return $email === null || $this->normalizer->isMarketplaceEmail($email) ? null : $this->identities->find(Identities::EMAIL, $email);
    }

    /**
     * @param  list<CartItemRecord>  $items
     */
    private function replaceItems(string $cartId, array $items, string $tenantId): void
    {
        DB::table('cart_items')->where('tenant_id', $tenantId)->where('cart_id', $cartId)->delete();

        $rows = array_map(fn (CartItemRecord $i) => [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'cart_id' => $cartId,
            'external_id' => $i->externalId,
            'product_external_id' => $i->productExternalId,
            'variant_external_id' => $i->variantExternalId,
            'sku' => $i->sku,
            'name' => $i->name,
            'quantity' => $i->quantity,
            'unit_price' => $i->unitPrice,
            'unit_price_with_tax' => $i->unitPriceWithTax,
            'image_url' => $i->imageUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ], $items);

        if ($rows !== []) {
            DB::table('cart_items')->insert($rows);
        }
    }

    private function contentHash(CartRecord $c): string
    {
        $lines = array_map(fn (CartItemRecord $i) => [$i->variantExternalId ?? $i->externalId, $i->quantity, $i->unitPriceWithTax ?? $i->unitPrice], $c->items);
        sort($lines);

        return hash('sha256', (string) json_encode([$c->memberExternalId, $c->email, $lines]));
    }
}
