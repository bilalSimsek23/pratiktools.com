<?php

namespace App\Platform;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\Tenant;
use App\Site\SiteOwner;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suspends or reactivates a tenant. Data is never deleted. A suspended
 * tenant is refused everywhere its status is already checked: panel
 * (PanelTenant), API tokens (ResolveTenantContext), queued jobs
 * (SetTenantContext: campaigns, automations, dispatch, sync, sends),
 * scheduler fan-out, webhooks and console --tenant lookups (active-only
 * directory functions). Runs in the tenant's own context (RLS).
 */
final class TenantLifecycle
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit, private readonly SiteOwner $siteOwner) {}

    public function suspend(string $tenantId, string $reason, Actor $actor): void
    {
        $reason = trim(mb_substr($reason, 0, 255));
        if ($reason === '') {
            throw new ProvisioningError('Askıya alma nedeni gerekli.');
        }
        if ($this->isSiteOwner($tenantId)) {
            throw new ProvisioningError('Bu müşteri pratiktools.com web sitesinin sahibi; askıya alınamaz. Önce site sahipliği kendi akışıyla değiştirilmeli.');
        }
        $this->change($tenantId, Tenant::STATUS_SUSPENDED, ['suspended_at' => now(), 'suspension_reason' => $reason], AuditAction::TenantSuspended, ['reason' => $reason], $actor);
    }

    public function reactivate(string $tenantId, Actor $actor): void
    {
        $this->change($tenantId, Tenant::STATUS_ACTIVE, ['suspended_at' => null, 'suspension_reason' => null], AuditAction::TenantReactivated, [], $actor);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $metadata
     */
    private function change(string $tenantId, string $status, array $columns, AuditAction $action, array $metadata, Actor $actor): void
    {
        if (! Str::isUuid($tenantId)) {
            throw new ProvisioningError('Müşteri bulunamadı.');
        }
        $this->context->run($tenantId, fn () => DB::transaction(function () use ($tenantId, $status, $columns, $action, $metadata, $actor) {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->first() ?? throw new ProvisioningError('Müşteri bulunamadı.');
            if ($tenant->status === $status) {
                return;
            }
            $tenant->update(['status' => $status] + $columns);
            $this->audit->record($action, 'tenant', $tenantId, $metadata, $actor);
        }));
    }

    private function isSiteOwner(string $tenantId): bool
    {
        // Only an active tenant can be suspended, so the active-only lookup suffices.
        $owner = $this->siteOwner->tenantId();

        return $owner !== null && strtolower($owner) === strtolower($tenantId);
    }
}
