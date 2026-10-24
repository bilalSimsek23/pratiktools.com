<?php

namespace App\Email\Contracts;

use App\Audit\Actor;
use App\Models\TenantIntegration;

interface EmailProviderFactory
{
    /**
     * Build the provider for one tenant integration. $purpose names why the
     * credentials are decrypted (audited by the vault).
     */
    public function make(TenantIntegration $integration, string $purpose, ?Actor $actor = null): EmailProvider;
}
