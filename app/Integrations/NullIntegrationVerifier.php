<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationVerifier;
use App\Models\TenantIntegration;
use App\Security\SensitiveValue;

/**
 * F0 placeholder: no provider connectors exist yet, so nothing is called.
 */
final class NullIntegrationVerifier implements IntegrationVerifier
{
    public function verify(TenantIntegration $integration, SensitiveValue $secret): VerificationResult
    {
        return VerificationResult::notImplemented();
    }
}
