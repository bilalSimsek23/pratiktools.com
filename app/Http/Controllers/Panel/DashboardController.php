<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Notifications\TenantNotifications;
use App\Operations\MarketingDashboard;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    use PanelHelpers;

    public function __invoke(MarketingDashboard $dashboard, TenantNotifications $notifications): View
    {
        $this->authorizePanel(Permission::TenantView);
        $data = $dashboard->data();
        $tenantId = app(TenantContext::class)->requireTenant();

        return view('panel.dashboard', $data + [
            'customers' => DB::table('customers')->where('tenant_id', $tenantId)->count(),
            'waitingRuns' => DB::table('automation_runs')->where('tenant_id', $tenantId)->where('status', 'waiting')->count(),
            'notifications' => $notifications->forMember($this->userId(), $this->role(), unreadOnly: true, limit: 8),
            'canApprove' => $this->can(Permission::CampaignsApprove),
        ]);
    }
}
