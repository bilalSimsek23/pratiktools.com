<?php

namespace App\Commerce;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Commerce\Data\ConnectionCheck;
use App\Integrations\Errors\ProviderError;
use App\Models\TenantIntegration;

/**
 * Read-only connection test. Updates the integration status: a rejected
 * credential needs a person (action_required), anything else is an error
 * that may resolve by itself.
 */
final class ConnectionTester
{
    public function __construct(
        private readonly CommerceSourceFactory $sources,
        private readonly AuditLogger $audit,
    ) {}

    public function test(TenantIntegration $integration, Actor $actor): ConnectionCheck
    {
        try {
            $check = $this->sources->for($integration, 'connection_test', $actor)->testConnection();
        } catch (ProviderError $e) {
            $check = new ConnectionCheck(false, [], $e->category, $e->getMessage());
        }

        $integration->forceFill([
            'status' => $check->ok ? 'connected' : ($check->errorCategory === ProviderError::AUTH ? 'action_required' : 'error'),
            'last_error' => $check->message,
            'last_verified_at' => $check->ok ? now() : $integration->last_verified_at,
        ])->save();

        $this->audit->record(AuditAction::IntegrationVerified, 'tenant_integration', $integration->id, [
            'result' => $integration->status,
            'checks' => $check->checks,
        ], $actor);

        return $check;
    }
}
