<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Site\SiteOwner;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marketing panel: a signed-in tenant user (session guard "web") working in
 * the tenant chosen at login. Membership and tenant status are re-checked
 * on every request; the context is cleared afterwards. The tenant is never
 * taken from request input.
 */
final class PanelTenant
{
    public const SESSION_TENANT = 'panel.tenant_id';

    public const ROLE_LABELS = ['owner' => 'Mağaza sahibi', 'admin' => 'Pazarlama yöneticisi', 'marketer' => 'Pazarlama editörü', 'viewer' => 'İzleyici', 'approver' => 'Onaylayıcı'];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user === null) {
            return redirect()->route('panel.login');
        }
        $tenantId = $request->session()->get(self::SESSION_TENANT);
        if (! is_string($tenantId)) {
            return redirect()->route('panel.tenants');
        }

        try {
            $this->context->setUser((string) $user->getAuthIdentifier());
            $membership = TenantUser::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)
                ->where('user_id', $user->getAuthIdentifier())->where('status', 'active')->first();
            if ($membership === null) {
                $request->session()->forget(self::SESSION_TENANT);

                return redirect()->route('panel.tenants');
            }
            $this->context->setTenant($tenantId);
            abort_unless(Tenant::query()->whereKey($tenantId)->value('status') === Tenant::STATUS_ACTIVE, 403);
            $this->context->setMembership($membership);
            View::share([
                'panelTenantName' => Tenant::query()->whereKey($tenantId)->value('name'),
                'panelRole' => self::ROLE_LABELS[$membership->role->value],
                'panelCan' => fn (Permission $p): bool => $membership->role->allows($p),
                // Website menu only in the site-owner tenant.
                'panelSiteOwner' => app(SiteOwner::class)->is($tenantId),
                'pendingApprovals' => $membership->role->allows(Permission::CampaignsApprove)
                    ? DB::table('campaign_approvals')->where('tenant_id', $tenantId)->where('status', 'pending')->where('requested_by', '<>', $user->getAuthIdentifier())->count() : 0,
            ]);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
