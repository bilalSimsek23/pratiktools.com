<?php

namespace App\Consent;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a disagreement between consent sources instead of silently
 * overwriting one with the other. One open row per recipient, channel and
 * kind; a repeated observation refreshes its details.
 */
final class ConsentConflicts
{
    public const OFFICIAL_ONAY_AFTER_LOCAL_RET = 'OFFICIAL_ONAY_AFTER_LOCAL_RET';

    public const OFFICIAL_SOURCES_DISAGREE = 'OFFICIAL_SOURCES_DISAGREE';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $details  never an address
     */
    public function open(string $recipientId, ConsentChannel $channel, string $kind, array $details): void
    {
        DB::statement(
            "INSERT INTO consent_conflicts (id, tenant_id, recipient_id, channel, kind, status, details, detected_at)
             VALUES (?, ?, ?, ?, ?, 'open', ?::jsonb, now())
             ON CONFLICT (tenant_id, recipient_id, channel, kind) DO UPDATE SET status = 'open', details = EXCLUDED.details, detected_at = now(), resolved_at = NULL",
            [(string) Str::uuid7(), $this->context->requireTenant(), $recipientId, $channel->value, $kind, json_encode($details)],
        );
    }

    /**
     * Whether the recipient has an active local block for the channel: a
     * suppression, or the recipient's own unsubscribe (local RET).
     */
    public function locallyRevoked(string $recipientId, ConsentChannel $channel): bool
    {
        $tenantId = $this->context->requireTenant();

        return DB::table('suppressions')->where('tenant_id', $tenantId)->where('recipient_id', $recipientId)->whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $channel->value))->exists();
    }
}
