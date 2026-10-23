<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical order. Marketplace orders are kept but never create customer identities.
 */
class Order extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'orders';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_marketplace' => 'boolean', 'counts_as_revenue' => 'boolean', 'attributes' => 'array', 'placed_at' => 'datetime', 'source_updated_at' => 'datetime', 'grand_total' => 'float', 'net_total' => 'float'];
    }
}
