<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal canonical customer. Extended by the commerce sync phase (F1).
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $email
 * @property ?string $first_name
 * @property ?string $last_name
 */
class Customer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['email', 'first_name', 'last_name'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'member_since' => 'datetime',
            'source_updated_at' => 'datetime',
            'is_guest' => 'boolean',
            'is_active' => 'boolean',
            'source_consent' => 'array',
            'attributes' => 'array',
        ];
    }
}
