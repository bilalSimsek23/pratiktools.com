<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant data encryption key, stored only in KEK-wrapped form.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $version
 * @property string $wrapped_key
 * @property string $kek_id
 * @property string $status
 */
class TenantDataKey extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = ['version', 'wrapped_key', 'kek_id', 'status', 'retired_at'];

    protected $hidden = ['wrapped_key'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'retired_at' => 'datetime',
        ];
    }
}
