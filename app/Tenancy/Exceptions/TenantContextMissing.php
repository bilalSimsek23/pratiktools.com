<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant data is touched without a tenant context. Tenant access
 * is fail-closed: there is no "all tenants" fallback.
 */
final class TenantContextMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant context is set; tenant data cannot be accessed.');
    }
}
