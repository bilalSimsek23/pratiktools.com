<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical product card.
 */
class Product extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'products';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'attributes' => 'array', 'source_created_at' => 'datetime', 'total_stock' => 'float'];
    }
}
