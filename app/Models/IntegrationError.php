<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Normalized provider error. Never contains secrets or payloads.
 *
 * @property string $id
 * @property string $integration_id
 * @property ?string $sync_run_id
 * @property string $category
 * @property ?string $operation
 * @property string $message
 * @property array<string, mixed> $context
 * @property Carbon $occurred_at
 */
class IntegrationError extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'integration_errors';

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'datetime'];
    }
}
