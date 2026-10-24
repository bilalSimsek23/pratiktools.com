<?php

namespace App\Console\Commands\Concerns;

use App\Models\TenantIntegration;
use RuntimeException;

trait FindsOneSignalIntegration
{
    protected function oneSignalIntegration(): TenantIntegration
    {
        return TenantIntegration::query()->where('provider', 'onesignal')->where('capability', 'push')->first()
            ?? throw new RuntimeException('The tenant has no OneSignal integration. Run onesignal:connect first.');
    }
}
