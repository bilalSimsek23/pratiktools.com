<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Super Admin identity. Lives on the platform connection (saas_platform
 * role), which has no access to tenant personal data.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property ?string $two_factor_secret
 * @property ?Carbon $two_factor_confirmed_at
 * @property string $status
 */
class PlatformAdmin extends Authenticatable
{
    use HasUuids;

    protected $connection = 'pgsql_platform';

    protected $fillable = ['name', 'email', 'password', 'status'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
