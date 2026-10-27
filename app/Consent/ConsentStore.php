<?php

namespace App\Consent;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records consent observations.
 *
 * - consents keeps the latest state per recipient, channel, recipient type
 *   and source provider. An observation older than the stored one ("as of"
 *   time) never overwrites it, so a late-arriving historic movement cannot
 *   undo a fresher status; at the same instant a RET is never replaced.
 * - consent_events gets one append-only row per observed change, unique per
 *   provider event key, so re-reading the same data adds nothing.
 * - No automation is triggered (shadow mode).
 */
final class ConsentStore
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $evidence
     * @return bool whether the stored state changed
     */
    public function observe(
        string $recipientId,
        ConsentChannel $channel,
        RecipientType $recipientType,
        ConsentSourceProvider $provider,
        ConsentStatus $status,
        CarbonInterface $asOf,
        string $eventKey,
        ?string $source = null,
        ?CarbonInterface $consentDate = null,
        ?string $externalId = null,
        bool $verified = true,
        bool $backfill = false,
        array $metadata = [],
        array $evidence = [],
        ?string $reconciliationBatchId = null,
    ): bool {
        $tenantId = $this->context->requireTenant();
        $asOfString = CarbonImmutable::instance($asOf)->utc()->format('Y-m-d\TH:i:s.uP');

        $current = DB::selectOne(
            'SELECT status, metadata->>\'as_of\' AS as_of FROM consents
             WHERE tenant_id = ? AND recipient_id = ? AND channel = ? AND recipient_type = ? AND source_provider = ? FOR UPDATE',
            [$tenantId, $recipientId, $channel->value, $recipientType->value, $provider->value],
        );

        $currentAsOf = $current?->as_of === null ? null : CarbonImmutable::parse($current->as_of);
        // Same instant, different answer: a RET is never replaced (RET wins ties).
        $newer = $currentAsOf === null || $currentAsOf->lt(CarbonImmutable::instance($asOf))
            || ($currentAsOf->eq(CarbonImmutable::instance($asOf)) && ($current->status !== ConsentStatus::Revoked->value || $status === ConsentStatus::Revoked));
        $changed = $current === null || $current->status !== $status->value;

        if ($changed) {
            DB::affectingStatement(
                'INSERT INTO consent_events (id, tenant_id, recipient_id, name, channel, recipient_type, source_provider, old_status, new_status, source, provider_transaction_id, event_key, occurred_at, observed_at, is_backfill, metadata, reconciliation_batch_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), ?, ?::jsonb, ?)
                 ON CONFLICT (tenant_id, source_provider, event_key) DO NOTHING',
                [
                    (string) Str::uuid7(), $tenantId, $recipientId, 'CONSENT_'.$status->value, $channel->value, $recipientType->value, $provider->value,
                    $newer ? $current?->status : null, $status->value, $source, $externalId, mb_substr($eventKey, 0, 191), $asOfString,
                    $backfill || ! $newer, json_encode(array_filter(['stale_observation' => ! $newer ?: null] + $metadata, fn ($v) => $v !== null)), $reconciliationBatchId,
                ],
            );
        }

        if (! $newer) {
            return false;
        }

        $meta = json_encode(['as_of' => $asOfString] + $metadata);

        if ($current === null) {
            DB::statement(
                'INSERT INTO consents (id, tenant_id, recipient_id, channel, recipient_type, source_provider, status, source, consent_date, last_verified_at, external_id, evidence, metadata, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, now(), now())
                 ON CONFLICT (tenant_id, recipient_id, channel, recipient_type, source_provider) DO NOTHING',
                [
                    (string) Str::uuid7(), $tenantId, $recipientId, $channel->value, $recipientType->value, $provider->value, $status->value, $source,
                    $consentDate, $verified ? now() : null, $externalId, json_encode($evidence), $meta,
                ],
            );

            return true;
        }

        DB::update(
            'UPDATE consents SET status = ?, source = COALESCE(?, source), consent_date = COALESCE(?, consent_date),
                last_verified_at = CASE WHEN ? THEN now() ELSE last_verified_at END, external_id = COALESCE(?, external_id),
                evidence = evidence || ?::jsonb, metadata = metadata || ?::jsonb, updated_at = now()
             WHERE tenant_id = ? AND recipient_id = ? AND channel = ? AND recipient_type = ? AND source_provider = ?',
            [
                $status->value, $source, $consentDate, $verified, $externalId, json_encode($evidence), $meta,
                $tenantId, $recipientId, $channel->value, $recipientType->value, $provider->value,
            ],
        );

        return $changed;
    }

    /**
     * Marks a stored observation as re-verified without a state change.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function touch(string $recipientId, ConsentChannel $channel, RecipientType $type, ConsentSourceProvider $provider, array $metadata = []): void
    {
        DB::update(
            'UPDATE consents SET last_verified_at = now(), metadata = metadata || ?::jsonb, updated_at = now()
             WHERE tenant_id = ? AND recipient_id = ? AND channel = ? AND recipient_type = ? AND source_provider = ?',
            [json_encode($metadata), $this->context->requireTenant(), $recipientId, $channel->value, $type->value, $provider->value],
        );
    }
}
