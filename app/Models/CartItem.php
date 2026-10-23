<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Cart line.
 */
class CartItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'cart_items';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
