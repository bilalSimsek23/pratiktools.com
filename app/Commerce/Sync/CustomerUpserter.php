<?php

namespace App\Commerce\Sync;

use App\Commerce\Data\CustomerRecord;
use App\Commerce\Identity\ContactNormalizer;
use App\Commerce\Identity\CustomerIdentityService as Identities;
use App\Events\Customer\CustomerEventName;
use App\Events\Customer\EventRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Members and newsletter subscribers -> canonical customers.
 *
 * Matching is deterministic: member id first, then normalized e-mail. A
 * member whose e-mail already belongs to a guest or subscriber customer is
 * attached to that customer. An e-mail owned by a different member is not
 * merged; the conflict is recorded for review. Permission flags are kept
 * as source data only (source_consent).
 */
final class CustomerUpserter
{
    private const TRACKED = ['email', 'phone', 'first_name', 'last_name', 'birth_date', 'gender', 'city', 'district', 'member_since', 'is_active', 'source_consent', 'groups'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Identities $identities,
        private readonly ContactNormalizer $normalizer,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  list<CustomerRecord>  $records
     */
    public function upsert(array $records, string $source, bool $backfill, UpsertResult $result): void
    {
        $tenantId = $this->context->requireTenant();

        $members = array_values(array_filter($records, fn ($r) => $r->kind === CustomerRecord::KIND_MEMBER));
        $memberMap = $this->identities->lookup(Identities::MEMBER, array_map(fn ($r) => $r->externalId, $members));
        $emailMap = $this->identities->lookup(Identities::EMAIL, array_values(array_filter(array_map(fn ($r) => $this->usableEmail($r->email), $records))));
        $stored = $this->storedState(array_values(array_unique(array_merge(array_values($memberMap), array_values($emailMap)))));
        // Customers that already are a member: an e-mail match must not
        // fold a second member into them.
        $memberOwners = array_flip($this->memberOwners(array_values($emailMap)));

        DB::transaction(function () use ($records, $source, $backfill, $result, $tenantId, &$memberMap, &$emailMap, &$stored, &$memberOwners) {
            foreach ($records as $record) {
                $email = $this->usableEmail($record->email);
                $phone = $this->normalizer->phone($record->phone);
                $byEmail = $email !== null ? ($emailMap[$email] ?? null) : null;

                $customerId = $record->kind === CustomerRecord::KIND_MEMBER
                    ? ($memberMap[$record->externalId] ?? ($byEmail !== null && ! isset($memberOwners[$byEmail]) ? $byEmail : null))
                    : $byEmail;

                if ($record->kind === CustomerRecord::KIND_SUBSCRIBER) {
                    $customerId = $this->upsertSubscriber($record, $customerId, $email, $phone, $tenantId, $source, $backfill, $result);
                } else {
                    $customerId = $this->upsertMember($record, $customerId, $email, $phone, $stored[$customerId] ?? null, $tenantId, $source, $backfill, $result);
                    $memberMap[$record->externalId] = $customerId;
                    $memberOwners[$customerId] = true;
                    $this->identities->attach($customerId, Identities::MEMBER, $record->externalId, $source);
                }

                if ($email !== null && $this->identities->attach($customerId, Identities::EMAIL, $email, $source)) {
                    $emailMap[$email] = $customerId;
                }
                $this->identities->attach($customerId, Identities::PHONE, $phone, $source);
                $result->touch($customerId);
            }
        });
    }

    private function upsertMember(CustomerRecord $r, ?string $customerId, ?string $email, ?string $phone, ?object $stored, string $tenantId, string $source, bool $backfill, UpsertResult $result): string
    {
        $consent = [
            'email_opt_in' => $r->emailOptIn,
            'sms_opt_in' => $r->smsOptIn,
            'permission_updated_at' => $r->permissionUpdatedAt?->toIso8601String(),
            'source' => $source.':member',
        ];

        $fields = [
            'email' => $email ?? $r->email,
            'phone' => $phone,
            'first_name' => $r->firstName,
            'last_name' => $r->lastName,
            'birth_date' => $r->birthDate?->toDateString(),
            'gender' => $r->gender,
            'city' => $r->city,
            'district' => $r->district,
            'member_since' => $r->memberSince,
            'is_guest' => false,
            'is_active' => $r->isActive,
            'source_updated_at' => $r->updatedAt,
        ];
        $hash = $this->hash($fields + ['source_consent' => $consent, 'groups' => $r->groups]);
        $attributes = $r->attributes + ['groups' => $r->groups, 'source_hash' => $hash];

        if ($customerId === null) {
            $customerId = (string) Str::uuid7();
            DB::table('customers')->insert($fields + [
                'id' => $customerId,
                'tenant_id' => $tenantId,
                'source_consent' => json_encode($consent),
                'attributes' => json_encode($attributes),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $result->inserted++;
            $this->events->record(CustomerEventName::CustomerCreated, $customerId, $r->memberSince ?? now(), $source, "member:{$r->externalId}:created", ['source_kind' => 'member'], $backfill);

            return $customerId;
        }

        if (($stored->source_hash ?? null) === $hash) {
            $result->skipped++;

            return $customerId;
        }

        DB::update(
            'UPDATE customers SET email = ?, phone = ?, first_name = ?, last_name = ?, birth_date = ?, gender = ?, city = ?, district = ?, member_since = ?,
                is_guest = false, is_active = ?, source_updated_at = ?, source_consent = source_consent || ?::jsonb, attributes = attributes || ?::jsonb, updated_at = now()
             WHERE id = ? AND tenant_id = ?',
            [
                $fields['email'], $phone, $r->firstName, $r->lastName, $fields['birth_date'], $r->gender, $r->city, $r->district, $r->memberSince,
                $r->isActive, $r->updatedAt, json_encode($consent), json_encode($attributes), $customerId, $tenantId,
            ],
        );
        $result->updated++;

        $this->events->record(CustomerEventName::CustomerUpdated, $customerId, $r->updatedAt ?? now(), $source, "member:{$r->externalId}:{$hash}", [
            'changed' => $stored?->source_hash === null ? ['linked_member'] : ['profile'],
        ], $backfill);

        return $customerId;
    }

    private function upsertSubscriber(CustomerRecord $r, ?string $customerId, ?string $email, ?string $phone, string $tenantId, string $source, bool $backfill, UpsertResult $result): string
    {
        $newsletter = json_encode([
            'email_opt_in' => $r->emailOptIn,
            'sms_opt_in' => $r->smsOptIn,
            'subscribed_at' => $r->attributes['subscribed_at'] ?? null,
            'subscriber_id' => $r->externalId,
        ]);

        if ($customerId === null) {
            $customerId = (string) Str::uuid7();
            DB::table('customers')->insert([
                'id' => $customerId,
                'tenant_id' => $tenantId,
                'email' => $email ?? $r->email,
                'phone' => $phone,
                'first_name' => $r->firstName,
                'last_name' => $r->lastName,
                'is_guest' => true,
                'source_consent' => json_encode(['newsletter' => json_decode($newsletter, true)]),
                'attributes' => json_encode(['newsletter_only' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $result->inserted++;
            $this->events->record(CustomerEventName::CustomerCreated, $customerId, $r->updatedAt ?? now(), $source, "subscriber:{$r->externalId}:created", ['source_kind' => 'subscriber'], $backfill);
        } else {
            $changed = DB::update(
                "UPDATE customers SET source_consent = jsonb_set(source_consent, '{newsletter}', ?::jsonb), updated_at = now()
                 WHERE id = ? AND tenant_id = ? AND (source_consent -> 'newsletter') IS DISTINCT FROM ?::jsonb",
                [$newsletter, $customerId, $tenantId, $newsletter],
            );
            $changed > 0 ? $result->updated++ : $result->skipped++;
        }

        $this->identities->attach($customerId, Identities::SUBSCRIBER, $r->externalId, $source);

        return $customerId;
    }

    private function usableEmail(?string $email): ?string
    {
        $email = $this->normalizer->email($email);

        return $email !== null && ! $this->normalizer->isMarketplaceEmail($email) ? $email : null;
    }

    /**
     * @param  list<string>  $customerIds
     * @return list<string>
     */
    private function memberOwners(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return DB::table('customer_identities')
            ->where('tenant_id', $this->context->requireTenant())
            ->where('type', Identities::MEMBER)
            ->whereIn('customer_id', array_values(array_unique($customerIds)))
            ->distinct()
            ->pluck('customer_id')
            ->all();
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, object>
     */
    private function storedState(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return DB::table('customers')
            ->whereIn('id', $customerIds)
            ->selectRaw("id, attributes->>'source_hash' AS source_hash")
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function hash(array $fields): string
    {
        $tracked = array_intersect_key($fields, array_flip(self::TRACKED));
        ksort($tracked);

        return substr(hash('sha256', (string) json_encode(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $tracked))), 0, 16);
    }
}
