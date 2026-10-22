<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Not scoped by BelongsToTenant because tenant_id is nullable for platform
 * webhooks; row-level security limits rows to the current tenant, or to
 * tenant-less rows in the explicit system context.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property ?string $integration_id
 * @property string $provider
 * @property ?string $event_type
 * @property ?string $external_event_id
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $headers_minimized
 * @property string $signature_status
 * @property string $processing_status
 * @property int $attempts
 * @property ?string $last_error
 */
class WebhookInboxItem extends Model
{
    use HasUuids;

    protected $table = 'webhook_inbox';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers_minimized' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
