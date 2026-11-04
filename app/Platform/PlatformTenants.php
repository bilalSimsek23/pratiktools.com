<?php

namespace App\Platform;

use App\Site\SiteOwner;
use App\Tenancy\TenantOnboarding;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Super Admin "Müşteriler" reads. Only the platform connection: column
 * grants on non-secret columns, plus the two member-directory functions
 * (names and e-mail addresses of tenant members, nothing else personal).
 * Never customers, consents, leads' contents, secrets or settings.
 */
final class PlatformTenants
{
    public function __construct(private readonly SiteOwner $siteOwner) {}

    private function db(): Connection
    {
        return DB::connection('pgsql_platform');
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function list(string $search = '', ?string $status = null): Collection
    {
        $db = $this->db();
        $owners = collect($db->select('SELECT * FROM app_platform_tenant_owners()'))->keyBy('tenant_id');
        $members = $db->table('tenant_users')->where('status', 'active')->selectRaw('tenant_id, count(*) AS n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $integrations = $db->table('tenant_integrations')->get(['tenant_id', 'provider', 'status'])->groupBy('tenant_id');
        $activity = $db->table('audit_logs')->whereNotNull('tenant_id')->selectRaw('tenant_id, max(created_at) AS at')->groupBy('tenant_id')->pluck('at', 'tenant_id');
        $siteOwner = $this->siteOwner->tenantId();
        $search = Str::lower(trim($search));

        return $db->table('tenants')->when(in_array($status, ['active', 'suspended'], true), fn ($q) => $q->where('status', $status))->orderBy('name')
            ->get(['id', 'name', 'slug', 'status', 'created_at', 'suspended_at', 'plan'])
            ->map(function (object $t) use ($owners, $members, $integrations, $activity, $siteOwner) {
                $t->owner = $owners[$t->id] ?? null;
                $t->members = (int) ($members[$t->id] ?? 0);
                $t->onboarding = TenantOnboarding::summarize($integrations[$t->id] ?? []);
                $t->last_activity = $activity[$t->id] ?? null;
                $t->site_owner = $t->id === $siteOwner;

                return $t;
            })
            ->filter(fn (object $t) => $search === '' || str_contains(Str::lower($t->name), $search) || str_contains($t->slug, $search)
                || ($t->owner !== null && str_contains(Str::lower((string) $t->owner->email), $search)))
            ->values();
    }

    public function find(string $id): ?object
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $t = $this->db()->table('tenants')->where('id', $id)
            ->first(['id', 'name', 'slug', 'status', 'country', 'timezone', 'currency', 'locale', 'contact_phone', 'plan', 'suspended_at', 'suspension_reason', 'created_at']);
        if ($t === null) {
            return null;
        }
        $t->owner = collect($this->db()->select('SELECT * FROM app_platform_tenant_owners()'))->firstWhere('tenant_id', $id);
        $t->platform_note = (string) $this->db()->table('platform_tenant_notes')->where('tenant_id', $id)->value('note');
        $t->site_owner = $id === $this->siteOwner->tenantId();

        return $t;
    }

    /**
     * @return Collection<int, object>
     */
    public function members(string $id): Collection
    {
        $pending = $this->db()->table('user_invitations')->where('tenant_id', $id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->selectRaw('membership_id, max(expires_at) AS expires_at')->groupBy('membership_id')->pluck('expires_at', 'membership_id');

        return collect($this->db()->select('SELECT * FROM app_platform_tenant_members(?)', [$id]))->map(function (object $m) use ($pending) {
            $m->invitation_expires_at = $pending[$m->membership_id] ?? null;

            return $m;
        });
    }

    /**
     * @return array<string, array{label: string, state: string, state_label: string, last_verified_at: ?string}>
     */
    public function integrations(string $id): array
    {
        $rows = $this->db()->table('tenant_integrations')->where('tenant_id', $id)->get(['provider', 'status', 'last_verified_at']);
        $summary = TenantOnboarding::summarize($rows);
        foreach ($summary as $provider => $s) {
            $summary[$provider]['last_verified_at'] = $rows->where('provider', $provider)->max('last_verified_at');
        }

        return $summary;
    }

    /**
     * Counters only.
     *
     * @return array<string, mixed>
     */
    public function usage(string $id): array
    {
        $db = $this->db();

        return [
            'campaigns' => $db->table('campaigns')->where('tenant_id', $id)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'automations' => (int) $db->table('automations')->where('tenant_id', $id)->count(),
            'automations_published' => (int) $db->table('automations')->where('tenant_id', $id)->where('status', 'published')->count(),
            'leads_30d' => (int) $db->table('lead_metrics_daily')->where('tenant_id', $id)->where('day', '>=', now()->subDays(30)->toDateString())->sum('accepted'),
            'leads_total' => (int) $db->table('lead_metrics_daily')->where('tenant_id', $id)->sum('accepted'),
            'dispatches' => (int) $db->table('campaign_dispatches')->where('tenant_id', $id)->count(),
            'last_sync' => $db->table('integration_sync_runs')->where('tenant_id', $id)->max('finished_at'),
            'last_activity' => $db->table('audit_logs')->where('tenant_id', $id)->max('created_at'),
        ];
    }

    /**
     * Recent audit trail of a tenant: action, actor type and time only.
     *
     * @return Collection<int, \stdClass>
     */
    public function audit(string $id, int $limit = 50): Collection
    {
        return $this->db()->table('audit_logs')->where('tenant_id', $id)->orderByDesc('created_at')->limit($limit)
            ->get(['action', 'actor_type', 'resource_type', 'created_at']);
    }

    /**
     * @return array{tenants: int, active: int, suspended: int, pending_invitations: int}
     */
    public function totals(): array
    {
        $db = $this->db();
        $byStatus = $db->table('tenants')->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status');

        return ['tenants' => (int) $byStatus->sum(), 'active' => (int) ($byStatus['active'] ?? 0), 'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'pending_invitations' => (int) $db->table('user_invitations')->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count()];
    }
}
