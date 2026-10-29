<?php

namespace App\Site;

use App\Tenancy\TenantDirectory;

/**
 * The tenant that owns the public website: the "owner" site setting, or
 * SITE_OWNER_TENANT only while that setting has never been saved
 * (bootstrap). None: the website cannot be edited and inquiries are refused.
 */
final class SiteOwner
{
    public function __construct(private readonly TenantDirectory $tenants, private readonly SiteSettings $settings) {}

    public function tenantId(): ?string
    {
        $slug = $this->settings->ownerSlug()['slug'];

        return $slug === null ? null : $this->tenants->idBySlug($slug);
    }

    public function is(?string $tenantId): bool
    {
        $owner = $this->tenantId();

        return $owner !== null && $tenantId !== null && hash_equals($owner, $tenantId);
    }
}
