<?php

namespace App\Commerce\Sync;

use App\Commerce\Data\OrderItemRecord;
use App\Commerce\Data\OrderRecord;
use App\Commerce\Identity\ContactNormalizer;
use App\Commerce\Identity\CustomerIdentityService as Identities;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orders and their items.
 *
 * Customer resolution is deterministic:
 * - store orders with a member id link to that member's customer (a stub
 *   customer is created when the member has not been synced yet);
 * - store guest orders link by normalized e-mail, creating a guest customer
 *   when the e-mail is new;
 * - marketplace orders are stored and flagged but never create a customer
 *   or an identity. They link only to a customer that already owns the
 *   member id, and never through an e-mail.
 *
 * Items are replaced whenever the order changes. Events are idempotent by
 * source_event_id; historical orders produce backfill events.
 */
final class OrderUpserter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Identities $identities,
        private readonly ContactNormalizer $normalizer,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  list<OrderRecord>  $records
     */
    public function upsert(array $records, string $source, bool $backfill, UpsertResult $result): void
    {
        if ($records === []) {
            return;
        }

        $tenantId = $this->context->requireTenant();
        $stored = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereIn('external_id', array_map(fn ($o) => $o->externalId, $records))
            ->selectRaw("external_id, id, customer_id, status_code, status_label, attributes->>'source_hash' AS source_hash")
            ->get()
            ->keyBy('external_id');

        DB::transaction(function () use ($records, $stored, $tenantId, $source, $backfill, $result) {
            $changedOrderIds = [];

            foreach ($records as $o) {
                $hash = substr(hash('sha256', (string) json_encode($o)), 0, 16);
                $existing = $stored[$o->externalId] ?? null;

                if ($existing !== null && $existing->source_hash === $hash) {
                    $result->skipped++;
                    $result->touch($existing->customer_id);

                    continue;
                }

                $customerId = $this->resolveCustomer($o, $source);
                $orderId = $this->write($o, $existing?->id, $customerId, $hash, $tenantId);
                $this->replaceItems($orderId, $o->items, $tenantId);
                $changedOrderIds[] = $orderId;

                $result->touch($customerId);
                $result->touch($existing?->customer_id);

                if ($existing === null) {
                    $result->inserted++;
                    $this->events->record(CustomerEventName::OrderPlaced, $customerId, $o->placedAt, $source, "order:{$o->externalId}:placed", [
                        'order_id' => $orderId,
                        'channel' => $o->channel,
                        'grand_total' => $o->grandTotal,
                        'currency' => $o->currency,
                        'item_count' => count($o->items),
                        'is_marketplace' => $o->isMarketplace,
                    ], $backfill || $o->placedAt->lt(CarbonImmutable::now()->subDay()));

                    continue;
                }

                $result->updated++;
                if ((string) $existing->status_code !== (string) $o->statusCode) {
                    $at = $o->updatedAt ?? CarbonImmutable::now();
                    $this->events->record(
                        CustomerEventName::OrderStatusChanged,
                        $customerId,
                        $at,
                        $source,
                        "order:{$o->externalId}:status:{$existing->status_code}>{$o->statusCode}:{$at->getTimestamp()}",
                        [
                            'order_id' => $orderId,
                            'from_status' => $existing->status_code === null ? null : (int) $existing->status_code,
                            'to_status' => $o->statusCode,
                            'from_label' => $existing->status_label,
                            'to_label' => $o->statusLabel,
                            'counts_as_revenue' => $o->countsAsRevenue,
                        ],
                        $backfill,
                    );
                }
            }

            CatalogLinks::resolve('order_items', 'order_id', $changedOrderIds, $tenantId);
        });
    }

    private function resolveCustomer(OrderRecord $o, string $source): ?string
    {
        $memberId = $o->memberExternalId;

        if ($o->isMarketplace) {
            return $memberId === null ? null : $this->identities->find(Identities::MEMBER, $memberId);
        }

        $email = $this->normalizer->email($o->email);
        $email = $email !== null && ! $this->normalizer->isMarketplaceEmail($email) ? $email : null;

        if ($memberId !== null) {
            $customerId = $this->identities->find(Identities::MEMBER, $memberId);

            if ($customerId === null) {
                // Member not synced yet (or deleted at the source). Keep the
                // link; the next member sync fills the profile.
                $customerId = $email !== null ? $this->identities->find(Identities::EMAIL, $email) : null;
                $customerId ??= $this->newCustomer($email, false, ['created_from' => 'order', 'member_pending' => true]);
                $this->identities->attach($customerId, Identities::MEMBER, $memberId, $source);
            }

            $this->identities->attach($customerId, Identities::EMAIL, $email, $source);

            return $customerId;
        }

        if ($email === null) {
            return null;
        }

        $customerId = $this->identities->find(Identities::EMAIL, $email);
        if ($customerId === null) {
            $customerId = $this->newCustomer($email, true, ['created_from' => 'guest_order']);
            $this->identities->attach($customerId, Identities::EMAIL, $email, $source);
        }

        return $customerId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function newCustomer(?string $email, bool $guest, array $attributes): string
    {
        $id = (string) Str::uuid7();
        DB::table('customers')->insert([
            'id' => $id,
            'tenant_id' => $this->context->requireTenant(),
            'email' => $email,
            'is_guest' => $guest,
            'attributes' => json_encode($attributes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function write(OrderRecord $o, ?string $id, ?string $customerId, string $hash, string $tenantId): string
    {
        $values = [
            'order_number' => $o->number,
            'customer_id' => $customerId,
            'external_member_id' => $o->memberExternalId,
            'email' => $o->email,
            'channel' => $o->channel,
            'source' => $o->source,
            'is_marketplace' => $o->isMarketplace,
            'status_code' => $o->statusCode,
            'status_label' => $o->statusLabel,
            'counts_as_revenue' => $o->countsAsRevenue,
            'placed_at' => $o->placedAt,
            'source_updated_at' => $o->updatedAt,
            'currency' => $o->currency,
            'net_total' => $o->netTotal,
            'grand_total' => $o->grandTotal,
            'discount_total' => $o->discountTotal,
            'shipping_total' => $o->shippingTotal,
            'coupon_code' => $o->couponCode,
            'coupon_amount' => $o->couponAmount,
            'attributes' => json_encode($o->attributes + ['source_hash' => $hash]),
            'updated_at' => now(),
        ];

        if ($id !== null) {
            DB::table('orders')->where('tenant_id', $tenantId)->where('id', $id)->update($values);

            return $id;
        }

        $id = (string) Str::uuid7();
        DB::table('orders')->insert($values + ['id' => $id, 'tenant_id' => $tenantId, 'external_id' => $o->externalId, 'created_at' => now()]);

        return $id;
    }

    /**
     * @param  list<OrderItemRecord>  $items
     */
    private function replaceItems(string $orderId, array $items, string $tenantId): void
    {
        DB::table('order_items')->where('tenant_id', $tenantId)->where('order_id', $orderId)->delete();

        $rows = array_map(fn (OrderItemRecord $i) => [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'external_id' => $i->externalId,
            'product_external_id' => $i->productExternalId,
            'variant_external_id' => $i->variantExternalId,
            'sku' => $i->sku,
            'barcode' => $i->barcode,
            'name' => $i->name,
            'quantity' => $i->quantity,
            'unit_list_price' => $i->unitListPrice,
            'unit_price' => $i->unitPrice,
            'tax_amount' => $i->taxAmount,
            'discount_amount' => $i->discountAmount,
            'row_total' => $i->rowTotal,
            'status_code' => $i->statusCode,
            'status_label' => $i->statusLabel,
            'created_at' => now(),
            'updated_at' => now(),
        ], $items);

        if ($rows !== []) {
            DB::table('order_items')->insert($rows);
        }
    }
}
