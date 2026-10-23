<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical cart state (no abandonment logic in F1).
 */
class Cart extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'carts';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['source_created_at' => 'datetime', 'last_seen_at' => 'datetime', 'total' => 'float'];
    }
}
