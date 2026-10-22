<?php

namespace App\Models;

use App\Audit\Exceptions\AuditLogImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Updates and deletes are rejected here, by missing grants and
 * by a database trigger.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $actor_type
 * @property ?string $actor_id
 * @property string $action
 * @property ?string $resource_type
 * @property ?string $resource_id
 * @property array<string, mixed> $metadata
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AuditLogImmutable);
        static::deleting(fn () => throw new AuditLogImmutable);
    }
}
