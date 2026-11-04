<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\User;

/**
 * Outcome of TenantProvisioner::provision(). created is false when the same
 * tenant/owner already existed (idempotent re-run; nothing was written).
 */
final readonly class ProvisionResult
{
    public function __construct(
        public Tenant $tenant,
        public User $owner,
        public bool $created,
        public ?IssuedInvitation $invitation,
    ) {}
}
