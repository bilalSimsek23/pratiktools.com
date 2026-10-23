<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Models\TenantIntegration;
use App\Security\OutboundUrlGuard;
use App\Security\Vault\SecretVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Creates or updates a tenant's Ticimax commerce integration. The member
 * code (UyeKodu) is read from STDIN only, so it never appears in shell
 * history, process lists or command arguments, and is stored only in the
 * vault.
 */
final class ConnectTicimax extends Command
{
    use RunsInTenantContext;

    protected $signature = 'ticimax:connect
        {--tenant= : Tenant slug}
        {--service-url= : Web service base URL, e.g. https://www.example.com/Servis/}
        {--storefront-url= : Storefront URL, e.g. https://www.example.com}
        {--secret-stdin : Read the Ticimax member code (UyeKodu) from STDIN}';

    protected $description = 'Connect a tenant to Ticimax (read-only commerce integration)';

    public function handle(SecretVault $vault, AuditLogger $audit, OutboundUrlGuard $guard): int
    {
        $serviceUrl = rtrim((string) $this->option('service-url'), '/').'/';
        $storefrontUrl = rtrim((string) $this->option('storefront-url'), '/');

        try {
            $guard->assertSafe($serviceUrl);
            $guard->assertSafe($storefrontUrl);
        } catch (InvalidArgumentException $e) {
            $this->error('Invalid URL: '.$e->getMessage());

            return self::INVALID;
        }

        $secret = null;
        if ($this->option('secret-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null ? $this->input->getStream() : STDIN;
            $secret = trim((string) stream_get_contents($stream));
            if ($secret === '') {
                $this->error('No secret on STDIN.');

                return self::INVALID;
            }
        }

        $integration = $this->inTenant(fn (string $tenantId) => DB::transaction(function () use ($tenantId, $serviceUrl, $storefrontUrl, $secret, $vault, $audit) {
            $integration = TenantIntegration::query()->where('provider', 'ticimax')->where('capability', 'commerce')->first();
            $created = $integration === null;

            $integration ??= new TenantIntegration(['provider' => 'ticimax', 'capability' => 'commerce', 'status' => 'pending']);
            $integration->tenant_id = $tenantId;
            $integration->settings = ['service_url' => $serviceUrl, 'storefront_url' => $storefrontUrl];

            if ($created && $secret === null) {
                throw new InvalidArgumentException('A new integration needs --secret-stdin.');
            }

            $previous = $integration->secret_fingerprint;
            if ($secret !== null) {
                $vault->seal($integration, $secret);
                $integration->status = 'pending';
            }
            $integration->save();

            if ($created) {
                $audit->record(AuditAction::IntegrationCreated, 'tenant_integration', $integration->id, ['provider' => 'ticimax', 'capability' => 'commerce']);
            }
            if ($secret !== null) {
                $audit->record($created ? AuditAction::IntegrationSecretCreated : AuditAction::IntegrationSecretChanged, 'tenant_integration', $integration->id, array_filter([
                    'previous_fingerprint' => $created ? null : $previous,
                    'fingerprint' => $integration->secret_fingerprint,
                ]));
            }

            return $integration;
        }));

        unset($secret);
        $this->info("Ticimax integration {$integration->id} saved (status: {$integration->status}). Run commerce:test next.");

        return self::SUCCESS;
    }
}
