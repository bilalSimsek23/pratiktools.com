<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Tenant lookups that are needed before a tenant context exists. Backed by
 * SECURITY DEFINER functions that return ids of active tenants only, so the
 * runtime role never gets to list or read other tenants' rows.
 */
final class TenantDirectory
{
    public function idBySlug(string $slug): ?string
    {
        return $this->scalar('SELECT app_tenant_id_by_slug(?) AS id', [$slug]);
    }

    public function idByPublicId(string $publicId): ?string
    {
        return $this->scalar('SELECT app_tenant_id_by_public_id(?) AS id', [$publicId]);
    }

    /**
     * @return list<string>
     */
    public function activeTenantIds(): array
    {
        return array_map(
            fn (object $row) => (string) $row->id,
            DB::select('SELECT app_active_tenant_ids() AS id'),
        );
    }

    /**
     * @param  list<string>  $bindings
     */
    private function scalar(string $sql, array $bindings): ?string
    {
        $id = DB::selectOne($sql, $bindings)?->id;

        return $id === null ? null : (string) $id;
    }
}
