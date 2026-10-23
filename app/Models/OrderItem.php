<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Order line.
 */
class OrderItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'order_items';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'row_total' => 'float'];
    }
}
