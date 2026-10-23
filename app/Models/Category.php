<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical product category (tenant-scoped, external id unique per tenant).
 */
class Category extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'categories';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
