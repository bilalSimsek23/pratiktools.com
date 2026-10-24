<?php

namespace App\Console\Commands\Concerns;

use App\Email\EmailProviderResolver;
use App\Models\TenantIntegration;
use RuntimeException;

trait FindsEmailIntegration
{
    protected function emailIntegration(?string $provider = null): TenantIntegration
    {
        $resolver = app(EmailProviderResolver::class);
        $integration = $provider === null || $provider === ''
            ? $resolver->primary()
            : $resolver->integrations()->first(fn (TenantIntegration $i) => $i->provider === $provider);

        return $integration ?? throw new RuntimeException('The tenant has no matching e-mail integration. Run email:connect-ses first.');
    }
}
