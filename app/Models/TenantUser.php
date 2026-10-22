<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a user in a tenant, with the user's role in that tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property TenantRole $role
 * @property string $status
 */
class TenantUser extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['role' => TenantRole::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
