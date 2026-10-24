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
 * Creates or updates a tenant's OneSignal integration. The App API key is
 * read from STDIN only and stored in the vault; scopes start read-only.
 */
final class ConnectOneSignal extends Command
{
    use RunsInTenantContext;

    protected $signature = 'onesignal:connect {--tenant= : Tenant slug} {--app-id= : OneSignal App ID (UUID)} {--secret-stdin : Read the App API key from STDIN}';

    protected $description = 'Connect a tenant to OneSignal (read-only scopes; sending stays off)';

    public function handle(SecretVault $vault, AuditLogger $audit): int
    {
        $appId = strtolower(trim((string) $this->option('app-id')));
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $appId)) {
            $this->error('Pass --app-id as the OneSignal App ID (UUID).');

            return self::INVALID;
        }

        $secret = null;
        if ($this->option('secret-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null ? $this->input->getStream() : STDIN;
            $secret = trim((string) stream_get_contents($stream));
            if ($secret === '') {
                $this->error('No API key on STDIN.');

                return self::INVALID;
            }
        }

        $integration = $this->inTenant(fn (string $tenantId) => DB::transaction(function () use ($tenantId, $appId, $secret, $vault, $audit) {
            $integration = TenantIntegration::query()->where('provider', 'onesignal')->where('capability', 'push')->first();
            $created = $integration === null;
            if ($created && $secret === null) {
                throw new \InvalidArgumentException('A new integration needs --secret-stdin.');
            }

            $integration ??= new TenantIntegration(['provider' => 'onesignal', 'capability' => 'push', 'status' => 'pending', 'scopes' => ['read_users', 'read_subscriptions']]);
            $integration->tenant_id = $tenantId;
            $integration->settings = ['app_id' => $appId] + array_diff_key((array) $integration->settings, ['app_id' => true]);

            $previous = $integration->secret_fingerprint;
            if ($secret !== null) {
                $vault->seal($integration, $secret);
                $integration->status = 'pending';
            }
            $integration->save();

            if ($created) {
                $audit->record(AuditAction::IntegrationCreated, 'tenant_integration', $integration->id, ['provider' => 'onesignal', 'capability' => 'push']);
            }
            if ($secret !== null) {
                $audit->record($created ? AuditAction::IntegrationSecretCreated : AuditAction::IntegrationSecretChanged, 'tenant_integration', $integration->id, array_filter([
                    'previous_fingerprint' => $created ? null : $previous, 'fingerprint' => $integration->secret_fingerprint,
                ]));
            }

            return $integration;
        }));

        unset($secret);
        $this->info("OneSignal integration {$integration->id} saved (status {$integration->status}). Run onesignal:check next.");

        return self::SUCCESS;
    }
}
