<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $sending_mode
 * @property array<string, mixed> $branding
 * @property ?string $primary_domain
 * @property ?string $tracking_domain
 * @property ?string $wheel_domain
 * @property ?string $email_sender_domain
 */
class TenantSetting extends Model
{
    use BelongsToTenant;

    public const SENDING_MODES = ['off', 'internal', 'pilot', 'live'];

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'sending_mode', 'branding', 'primary_domain', 'tracking_domain', 'wheel_domain', 'email_sender_domain'];

    protected $attributes = [
        'sending_mode' => 'off',
        'branding' => '{}',
    ];

    protected function casts(): array
    {
        return ['branding' => 'array'];
    }
}
