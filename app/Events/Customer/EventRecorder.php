<?php

namespace App\Events\Customer;

use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes versioned events into customer_events. Idempotent: the same
 * (source, source_event_id) is stored once, however often the source data
 * is read again.
 */
final class EventRecorder
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        CustomerEventName $name,
        ?string $customerId,
        CarbonInterface $occurredAt,
        string $source,
        string $sourceEventId,
        array $properties,
        bool $isBackfill = false,
    ): bool {
        $missing = array_diff($name->requiredProperties(), array_keys($properties));
        if ($missing !== []) {
            throw new InvalidArgumentException("{$name->value} v{$name->schemaVersion()} is missing: ".implode(', ', $missing));
        }

        return DB::affectingStatement(
            'INSERT INTO customer_events (id, tenant_id, customer_id, name, schema_version, occurred_at, received_at, source, source_event_id, is_backfill, properties)
             VALUES (?, ?, ?, ?, ?, ?, now(), ?, ?, ?, ?::jsonb)
             ON CONFLICT (tenant_id, source, source_event_id) DO NOTHING',
            [
                (string) Str::uuid7(),
                $this->context->requireTenant(),
                $customerId,
                $name->value,
                $name->schemaVersion(),
                $occurredAt->utc()->toDateTimeString('microsecond'),
                $source,
                mb_substr($sourceEventId, 0, 191),
                $isBackfill,
                json_encode($properties, JSON_THROW_ON_ERROR),
            ],
        ) === 1;
    }
}
