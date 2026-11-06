<?php

namespace App\Commerce;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\TenantIntegration;
use App\Security\OutboundUrlGuard;
use App\Security\Vault\SecretVault;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or updates the current tenant's Ticimax commerce integration: the
 * one place the ticimax:connect command and Super Admin › Entegrasyonlar ›
 * Ticimax save the service URLs and the member code (UyeKodu).
 *
 * The member code goes only into the vault (AES-256-GCM, bound to the
 * tenant and integration). A new code replaces the old ciphertext and the
 * integration waits for a new connection test; without one only the URLs
 * change. The audit trail holds fingerprints, never the code.
 */
final class TicimaxIntegrations
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly AuditLogger $audit,
        private readonly OutboundUrlGuard $guard,
        private readonly TenantContext $context,
    ) {}

    /**
     * Must run inside the tenant's context.
     *
     * @throws InvalidArgumentException An unsafe URL, or a new integration without a member code.
     */
    public function save(string $serviceUrl, string $storefrontUrl, #[\SensitiveParameter] ?string $secret, Actor $actor): TenantIntegration
    {
        $serviceUrl = rtrim(trim($serviceUrl), '/').'/';
        $storefrontUrl = rtrim(trim($storefrontUrl), '/');
        $secret = $secret === null ? null : trim($secret);
        $secret = $secret === '' ? null : $secret;

        try {
            $this->guard->assertSafe($serviceUrl);
            $this->guard->assertSafe($storefrontUrl);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Invalid URL: '.$e->getMessage());
        }

        return DB::transaction(function () use ($serviceUrl, $storefrontUrl, $secret, $actor) {
            $integration = TenantIntegration::query()->where('provider', 'ticimax')->where('capability', 'commerce')->lockForUpdate()->first();
            $created = $integration === null;
            if ($created && $secret === null) {
                throw new InvalidArgumentException('A new integration needs the member code (UyeKodu).');
            }

            $integration ??= new TenantIntegration(['provider' => 'ticimax', 'capability' => 'commerce', 'status' => 'pending']);
            $integration->tenant_id = $this->context->requireTenant();
            $before = $created ? [] : array_intersect_key((array) $integration->settings, ['service_url' => 1, 'storefront_url' => 1]);
            $after = ['service_url' => $serviceUrl, 'storefront_url' => $storefrontUrl];
            $changed = array_keys(array_diff_assoc($after, $before));
            $integration->settings = $after + (array) $integration->settings;

            $previous = $integration->secret_fingerprint;
            // A new code or another service endpoint: the last test no longer says anything.
            if ($secret !== null || in_array('service_url', $changed, true)) {
                $integration->forceFill(['status' => 'pending', 'last_error' => null, 'last_verified_at' => null]);
            }
            if ($secret !== null) {
                $this->vault->seal($integration, $secret);
            }
            $integration->save();

            if ($created) {
                $this->audit->record(AuditAction::IntegrationCreated, 'tenant_integration', $integration->id, ['provider' => 'ticimax', 'capability' => 'commerce'], $actor);
            } elseif ($changed !== []) {
                $this->audit->record(AuditAction::IntegrationSettingsChanged, 'tenant_integration', $integration->id,
                    ['changed' => $changed, 'from' => array_intersect_key($before, array_flip($changed)), 'to' => array_intersect_key($after, array_flip($changed))], $actor);
            }
            if ($secret !== null) {
                $this->audit->record($created ? AuditAction::IntegrationSecretCreated : AuditAction::IntegrationSecretChanged, 'tenant_integration', $integration->id, array_filter([
                    'previous_fingerprint' => $created ? null : $previous,
                    'fingerprint' => $integration->secret_fingerprint,
                ]), $actor);
            }

            return $integration;
        });
    }
}
