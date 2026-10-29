<?php

namespace App\Site;

use App\Tenancy\TenantDirectory;

/**
 * The tenant that owns the public website (config site.owner_tenant).
 * None configured: the website cannot be edited and inquiries are refused.
 */
final class SiteOwner
{
    public function __construct(private readonly TenantDirectory $tenants) {}

    public function tenantId(): ?string
    {
        $slug = config('site.owner_tenant');

        return is_string($slug) && $slug !== '' ? $this->tenants->idBySlug($slug) : null;
    }

    public function is(?string $tenantId): bool
    {
        $owner = $this->tenantId();

        return $owner !== null && $tenantId !== null && hash_equals($owner, $tenantId);
    }
}
