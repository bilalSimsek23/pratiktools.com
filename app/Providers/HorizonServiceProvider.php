<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Auth;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon is reachable only on the admin host, only for a Super Admin
     * who completed two-factor authentication in this session. There is no
     * "local environment" bypass.
     */
    protected function authorization(): void
    {
        Horizon::auth(function ($request): bool {
            $admin = Auth::guard('platform')->user();

            return $admin instanceof PlatformAdmin
                && $request->session()->get(EnsurePlatformTwoFactor::SESSION_KEY) === $admin->id;
        });
    }
}
