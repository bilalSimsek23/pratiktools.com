<?php

namespace App\Consent;

use App\Commerce\Identity\ContactNormalizer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consent recipients (addresses) and their links to customers.
 *
 * A recipient is created per normalized address; every customer carrying
 * that address is linked to it. Customers are never merged here: an
 * address shared by two members simply has two links (identity conflict),
 * and a phone number is never used to find a customer.
 */
final class RecipientDirectory
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ContactNormalizer $normalizer,
    ) {}

    /**
     * Normalized form and validity of an e-mail address.
     *
     * @return array{address: string, valid: bool, reason: ?string, marketplace: bool}|null
     */
    public function email(?string $raw): ?array
    {
        $trimmed = strtolower(trim((string) $raw));
        if ($trimmed === '') {
            return null;
        }

        $valid = $this->normalizer->email($trimmed);

        return [
            'address' => mb_substr($trimmed, 0, 320),
            'valid' => $valid !== null,
            'reason' => $valid === null ? 'syntax' : null,
            'marketplace' => $this->normalizer->isMarketplaceEmail($trimmed),
        ];
    }

    /**
     * @return array{address: string, valid: bool, reason: ?string, marketplace: bool}|null
     */
    public function phone(?string $raw): ?array
    {
        if (trim((string) $raw) === '') {
            return null;
        }
        $e164 = $this->normalizer->phone($raw);

        return $e164 === null ? null : ['address' => $e164, 'valid' => true, 'reason' => null, 'marketplace' => false];
    }

    /**
     * Find or create recipients; returns address => recipient id.
     *
     * @param  list<array{address: string, valid: bool, reason: ?string, marketplace: bool}>  $addresses
     * @return array<string, string>
     */
    public function ensure(string $type, array $addresses): array
    {
        $tenantId = $this->context->requireTenant();
        $byAddress = [];
        foreach ($addresses as $a) {
            $byAddress[$a['address']] = $a;
        }
        if ($byAddress === []) {
            return [];
        }

        foreach (array_chunk(array_values($byAddress), 1000) as $chunk) {
            $rows = [];
            $bindings = [];
            foreach ($chunk as $a) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?, now(), now())';
                array_push($bindings, (string) Str::uuid7(), $tenantId, $type, $a['address'], $a['valid'], $a['reason'], $a['marketplace']);
            }
            DB::statement(
                'INSERT INTO consent_recipients (id, tenant_id, address_type, address, is_valid, invalid_reason, is_marketplace, created_at, updated_at)
                 VALUES '.implode(', ', $rows).' ON CONFLICT (tenant_id, address_type, address) DO NOTHING',
                $bindings,
            );
        }

        return $this->lookup($type, array_keys($byAddress));
    }

    /**
     * @param  list<string>  $addresses
     * @return array<string, string>
     */
    public function lookup(string $type, array $addresses): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($addresses)), 5000) as $chunk) {
            $out += DB::table('consent_recipients')
                ->where('tenant_id', $this->context->requireTenant())
                ->where('address_type', $type)
                ->whereIn('address', $chunk)
                ->pluck('id', 'address')
                ->all();
        }

        return $out;
    }

    /**
     * @param  list<array{recipient_id: string, customer_id: string, link_source: string}>  $links
     */
    public function link(array $links): void
    {
        $tenantId = $this->context->requireTenant();
        foreach (array_chunk($links, 1000) as $chunk) {
            $rows = [];
            $bindings = [];
            foreach ($chunk as $l) {
                $rows[] = '(?, ?, ?, ?, now())';
                array_push($bindings, $tenantId, $l['recipient_id'], $l['customer_id'], $l['link_source']);
            }
            DB::statement(
                'INSERT INTO recipient_customers (tenant_id, recipient_id, customer_id, link_source, created_at)
                 VALUES '.implode(', ', $rows).' ON CONFLICT DO NOTHING',
                $bindings,
            );
        }
    }
}
