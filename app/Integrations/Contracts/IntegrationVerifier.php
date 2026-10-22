<?php

namespace App\Integrations\Contracts;

use App\Integrations\VerificationResult;
use App\Models\TenantIntegration;
use App\Security\SensitiveValue;

/**
 * Checks that an integration's credentials work against the provider.
 * Implemented per provider by the connector phases (F1+).
 */
interface IntegrationVerifier
{
    public function verify(TenantIntegration $integration, SensitiveValue $secret): VerificationResult;
}
