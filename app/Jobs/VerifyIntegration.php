<?php

namespace App\Jobs;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Integrations\ProviderRegistry;
use App\Models\TenantIntegration;
use App\Queue\TenantAwareJob;
use App\Security\Vault\SecretVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class VerifyIntegration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public readonly string $integrationId, ?string $tenantId = null, public readonly ?string $requestedBy = null)
    {
        $this->forTenant($tenantId);
        $this->onQueue('system');
    }

    public function handle(SecretVault $vault, ProviderRegistry $providers, AuditLogger $audit): void
    {
        $integration = TenantIntegration::query()->find($this->integrationId);

        if ($integration === null || ! $integration->hasSecret()) {
            return;
        }

        $actor = Actor::job(self::class);
        $secret = $vault->reveal($integration, 'verify', $actor);
        $result = $providers->verifier($integration->provider)->verify($integration, $secret);

        $integration->forceFill([
            'status' => $result->status,
            'last_error' => $result->error === null ? null : mb_substr($result->error, 0, 500),
            'last_verified_at' => $result->status === 'connected' ? now() : $integration->last_verified_at,
        ])->save();

        $audit->record(AuditAction::IntegrationVerified, 'tenant_integration', $integration->id, [
            'result' => $result->status,
            'requested_by' => $this->requestedBy,
        ], $actor);
    }
}
