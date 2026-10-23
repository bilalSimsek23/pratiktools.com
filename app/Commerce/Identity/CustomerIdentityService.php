<?php

namespace App\Commerce\Identity;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deterministic identity graph. One canonical customer per identity value
 * (unique per tenant); no probabilistic matching. When an identifier is
 * already owned by another customer, the conflict is recorded for review
 * instead of merging.
 */
final class CustomerIdentityService
{
    public const MEMBER = 'commerce_member';

    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const SUBSCRIBER = 'newsletter_subscriber';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $values
     * @return array<string, string> value => customer id
     */
    public function lookup(string $type, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return DB::table('customer_identities')
            ->where('tenant_id', $this->context->requireTenant())
            ->where('type', $type)
            ->whereIn('value', array_values(array_unique($values)))
            ->pluck('customer_id', 'value')
            ->all();
    }

    public function find(string $type, ?string $value): ?string
    {
        return $value === null ? null : ($this->lookup($type, [$value])[$value] ?? null);
    }

    /**
     * Attach an identity to a customer. Returns false (and records a review
     * item) when it already belongs to a different customer.
     */
    public function attach(string $customerId, string $type, ?string $value, string $source): bool
    {
        if ($value === null) {
            return true;
        }

        $tenantId = $this->context->requireTenant();

        $inserted = DB::affectingStatement(
            'INSERT INTO customer_identities (id, tenant_id, customer_id, type, value, source, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, now(), now())
             ON CONFLICT (tenant_id, type, value) DO NOTHING',
            [(string) Str::uuid7(), $tenantId, $customerId, $type, $value, $source],
        ) === 1;

        $owner = $inserted ? $customerId : $this->find($type, $value);

        if ($owner === $customerId) {
            if (! $inserted) {
                DB::table('customer_identities')->where('tenant_id', $tenantId)->where('type', $type)->where('value', $value)->update(['last_seen_at' => now()]);
            }

            return true;
        }

        DB::affectingStatement(
            "INSERT INTO customer_merges (id, tenant_id, primary_customer_id, secondary_customer_id, identity_type, identity_hash, status, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending_review', ?, now())
             ON CONFLICT (tenant_id, identity_type, identity_hash, secondary_customer_id) DO NOTHING",
            [(string) Str::uuid7(), $tenantId, $owner, $customerId, $type, hash('sha256', $value), 'identity already belongs to another customer'],
        );

        return false;
    }
}
