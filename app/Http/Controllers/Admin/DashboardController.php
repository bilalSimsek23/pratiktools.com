<?php

namespace App\Http\Controllers\Admin;

use App\Health\HealthChecker;
use App\Http\Controllers\Controller;
use App\Platform\PlatformOverview;
use App\Platform\PlatformTenants;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(PlatformOverview $overview, PlatformTenants $tenants, HealthChecker $health): View
    {
        return view('admin.dashboard', [
            'tenants' => $overview->tenants(),
            'totals' => $tenants->totals(),
            'health' => $health->check(),
        ]);
    }
}
