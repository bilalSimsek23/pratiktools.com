<?php

namespace App\Console\Commands\Concerns;

use App\Models\TenantIntegration;
use RuntimeException;

trait FindsCommerceIntegration
{
    protected function commerceIntegration(): TenantIntegration
    {
        return TenantIntegration::query()->where('capability', 'commerce')->orderBy('created_at')->first()
            ?? throw new RuntimeException('The tenant has no commerce integration. Run ticimax:connect first.');
    }
}
