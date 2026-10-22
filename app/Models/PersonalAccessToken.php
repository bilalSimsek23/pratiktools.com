<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token bound to exactly one tenant at login.
 *
 * @property string $id
 * @property ?string $tenant_id
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'tenant_id'];
}
