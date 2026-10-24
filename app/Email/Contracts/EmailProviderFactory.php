<?php

namespace App\Email\Contracts;

use App\Audit\Actor;
use App\Email\EmailCapabilities;
use App\Models\TenantIntegration;

interface EmailProviderFactory
{
    /**
     * Build the provider for one tenant integration. $purpose names why the
     * credentials are decrypted (audited by the vault).
     */
    public function make(TenantIntegration $integration, string $purpose, ?Actor $actor = null): EmailProvider;

    /**
     * Capabilities from settings alone, so guards can decide before any
     * credential is decrypted.
     */
    public function capabilities(TenantIntegration $integration): EmailCapabilities;
}
