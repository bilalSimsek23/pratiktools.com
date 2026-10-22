<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider connection of a tenant. The secret is stored only as an
 * AES-256-GCM envelope written by the SecretVault and is hidden from every
 * serialization of the model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $capability
 * @property string $status
 * @property ?string $encrypted_secret
 * @property ?int $secret_key_version
 * @property ?string $secret_fingerprint
 * @property list<string> $scopes
 * @property array<string, mixed> $settings
 * @property ?Carbon $last_verified_at
 * @property ?string $last_error
 */
class TenantIntegration extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['pending', 'connected', 'error', 'action_required', 'syncing', 'disabled'];

    protected $fillable = ['provider', 'capability', 'status', 'scopes', 'settings'];

    protected $hidden = ['encrypted_secret', 'secret_key_version'];

    protected $attributes = [
        'status' => 'pending',
        'scopes' => '[]',
        'settings' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'settings' => 'array',
            'secret_key_version' => 'integer',
            'last_verified_at' => 'datetime',
        ];
    }

    public function hasSecret(): bool
    {
        return $this->encrypted_secret !== null;
    }
}
