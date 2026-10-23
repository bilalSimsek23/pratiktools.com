<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Models\TenantIntegration;
use App\Security\Vault\SecretVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Creates or updates a tenant's read-only İYS consent integration. The API
 * credentials come from STDIN as JSON {"username": ..., "password": ...}
 * and go only into the vault.
 */
final class ConnectIys extends Command
{
    use RunsInTenantContext;

    protected $signature = 'iys:connect
        {--tenant= : Tenant slug}
        {--iys-code= : Service provider İYS code}
        {--brand-code= : Brand İYS code}
        {--environment=sandbox : sandbox or production}
        {--secret-stdin : Read {"username","password"} JSON from STDIN}';

    protected $description = 'Connect a tenant to İYS (read-only, shadow mode)';

    public function handle(SecretVault $vault, AuditLogger $audit): int
    {
        $environment = (string) $this->option('environment');
        if (! in_array($environment, ['sandbox', 'production'], true) || (int) $this->option('iys-code') <= 0 || (int) $this->option('brand-code') <= 0) {
            $this->error('Pass --iys-code, --brand-code and --environment=sandbox|production.');

            return self::INVALID;
        }

        $secret = null;
        if ($this->option('secret-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null ? $this->input->getStream() : STDIN;
            $decoded = json_decode(trim((string) stream_get_contents($stream)), true);
            if (! is_array($decoded) || ! is_string($decoded['username'] ?? null) || ! is_string($decoded['password'] ?? null)) {
                $this->error('STDIN must be JSON with username and password.');

                return self::INVALID;
            }
            $secret = json_encode(['username' => $decoded['username'], 'password' => $decoded['password']]);
            unset($decoded);
        }

        $integration = $this->inTenant(fn (string $tenantId) => DB::transaction(function () use ($tenantId, $environment, $secret, $vault, $audit) {
            $integration = TenantIntegration::query()->where('provider', 'iys')->where('capability', 'consent')->first();
            $created = $integration === null;
            if ($created && $secret === null) {
                throw new \InvalidArgumentException('A new integration needs --secret-stdin.');
            }

            $integration ??= new TenantIntegration(['provider' => 'iys', 'capability' => 'consent', 'status' => 'pending']);
            $integration->tenant_id = $tenantId;
            $integration->settings = [
                'iys_code' => (int) $this->option('iys-code'),
                'brand_code' => (int) $this->option('brand-code'),
                'environment' => $environment,
                'mode' => 'shadow',
            ] + array_intersect_key($integration->settings ?? [], array_flip(['push_username', 'push_password_hash']));

            $previous = $integration->secret_fingerprint;
            if ($secret !== null) {
                $vault->seal($integration, $secret);
                $integration->status = 'pending';
            }
            $integration->save();

            if ($created) {
                $audit->record(AuditAction::IntegrationCreated, 'tenant_integration', $integration->id, ['provider' => 'iys', 'capability' => 'consent']);
            }
            if ($secret !== null) {
                $audit->record($created ? AuditAction::IntegrationSecretCreated : AuditAction::IntegrationSecretChanged, 'tenant_integration', $integration->id, array_filter([
                    'previous_fingerprint' => $created ? null : $previous,
                    'fingerprint' => $integration->secret_fingerprint,
                ]));
            }

            return $integration;
        }));

        $this->info("İYS integration {$integration->id} saved ({$environment}, status {$integration->status}). Run consent:sync --kind=iys_test next.");

        return self::SUCCESS;
    }
}
