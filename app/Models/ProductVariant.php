<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sellable variant (SKU) of a product.
 */
class ProductVariant extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'product_variants';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'options' => 'array', 'stock_updated_at' => 'datetime', 'price' => 'float', 'sale_price' => 'float', 'stock' => 'float'];
    }
}
