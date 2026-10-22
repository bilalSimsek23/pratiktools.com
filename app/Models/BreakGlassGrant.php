<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A time-boxed, audited emergency access grant of a Super Admin to one
 * tenant. F0 records grants only; no data access path uses them yet.
 *
 * @property string $id
 * @property string $platform_admin_id
 * @property string $tenant_id
 * @property string $reason
 * @property Carbon $expires_at
 */
class BreakGlassGrant extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $connection = 'pgsql_platform';

    protected $fillable = ['platform_admin_id', 'tenant_id', 'reason', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
