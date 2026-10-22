<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application-level tenant isolation for Eloquent models. Complements, and
 * never replaces, the PostgreSQL row-level security policies.
 *
 * - Every query is scoped to the current tenant; without a context the query
 *   throws instead of returning an empty result.
 * - New records get the current tenant id; a different tenant id is rejected.
 * - Updates and deletes are refused for records of another tenant, and the
 *   tenant id of an existing record cannot be changed.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->requireTenant();

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tenantId);
            } elseif ($model->getAttribute('tenant_id') !== $tenantId) {
                throw new TenantMismatch;
            }
        });

        static::updating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->requireTenant();

            if ($model->isDirty('tenant_id') || $model->getOriginal('tenant_id') !== $tenantId) {
                throw new TenantMismatch;
            }
        });

        static::deleting(function (Model $model): void {
            if ($model->getOriginal('tenant_id') !== app(TenantContext::class)->requireTenant()) {
                throw new TenantMismatch;
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
