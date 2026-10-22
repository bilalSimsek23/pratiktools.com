<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Not tenant-scoped through BelongsToTenant: the tenants table is protected
 * by its own RLS policies (current tenant, or the signed-in user's
 * memberships). Tenants are never deleted by the runtime role.
 *
 * @property string $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $country
 * @property string $timezone
 * @property string $currency
 * @property string $locale
 */
class Tenant extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['id', 'public_id', 'name', 'slug', 'status', 'country', 'timezone', 'currency', 'locale'];

    /**
     * @return HasOne<TenantSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    /**
     * @return HasMany<TenantUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
