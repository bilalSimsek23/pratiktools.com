<?php

namespace App\Security\Vault;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\TenantDataKey;
use App\Models\TenantIntegration;
use App\Security\SensitiveValue;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\DecryptionFailed;
use App\Security\Vault\Exceptions\VaultException;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Envelope encryption for tenant integration secrets.
 *
 * - Each tenant has its own data encryption key (DEK), stored only wrapped
 *   by the KMS key encryption key (KEK).
 * - Secrets are encrypted with AES-256-GCM under the tenant DEK. The
 *   associated data binds the ciphertext to tenant, integration, provider
 *   and capability, so copying it to another row or tenant fails to decrypt.
 * - Every decryption is audited with its purpose.
 * - Decrypted DEKs and secrets are never cached, logged or serialized.
 *
 * All methods require a tenant context matching the integration's tenant.
 */
final class SecretVault
{
    private const ENVELOPE_VERSION = 1;

    public function __construct(
        private readonly KeyEncryptionService $kms,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create the tenant's first data key if it has none.
     */
    public function ensureDataKey(): TenantDataKey
    {
        $this->context->requireTenant();

        return TenantDataKey::query()->where('status', 'active')->first()
            ?? $this->createDataKey(1);
    }

    /**
     * Encrypt a secret onto the integration (not saved). The integration must
     * already have its id.
     */
    public function seal(TenantIntegration $integration, #[\SensitiveParameter] string $secret): void
    {
        $this->assertSameTenant($integration);

        if ($secret === '') {
            throw new VaultException('Secret must not be empty.');
        }

        $integration->id ??= $integration->newUniqueId();
        $key = $this->ensureDataKey();
        $dek = $this->unwrap($key);

        $box = Aes256Gcm::encrypt($dek, $secret, $this->aad($integration));

        $integration->encrypted_secret = json_encode([
            'v' => self::ENVELOPE_VERSION,
            'k' => $key->version,
            'n' => base64_encode($box['nonce']),
            'c' => base64_encode($box['ciphertext']),
            't' => base64_encode($box['tag']),
        ], JSON_THROW_ON_ERROR);
        $integration->secret_key_version = $key->version;
        $integration->secret_fingerprint = $this->fingerprint($secret);
    }

    /**
     * Decrypt the integration secret for an explicit purpose. Audited.
     */
    public function reveal(TenantIntegration $integration, string $purpose, ?Actor $actor = null): SensitiveValue
    {
        $this->assertSameTenant($integration);

        $secret = $this->decrypt($integration);

        $this->audit->record(
            AuditAction::SecretDecrypted,
            'tenant_integration',
            $integration->id,
            ['purpose' => $purpose, 'provider' => $integration->provider, 'capability' => $integration->capability],
            $actor,
        );

        return new SensitiveValue($secret);
    }

    /**
     * Create a new DEK version, re-encrypt every secret of the tenant with it
     * and retire the previous key. Returns the new version.
     */
    public function rotateDataKey(?Actor $actor = null): int
    {
        $this->context->requireTenant();

        return DB::transaction(function () use ($actor): int {
            $current = $this->ensureDataKey();
            $integrations = TenantIntegration::query()->whereNotNull('encrypted_secret')->lockForUpdate()->get();
            $plaintexts = $integrations->mapWithKeys(fn (TenantIntegration $i) => [$i->id => $this->decrypt($i)]);

            $current->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
            $next = $this->createDataKey($current->version + 1, $actor, audit: false);

            foreach ($integrations as $integration) {
                $this->seal($integration, $plaintexts[$integration->id]);
                $integration->save();
            }

            $this->audit->record(AuditAction::DataKeyRotated, 'tenant_data_key', $next->id, [
                'from_version' => $current->version,
                'to_version' => $next->version,
                'reencrypted' => $integrations->count(),
            ], $actor);

            return $next->version;
        });
    }

    /**
     * Re-wrap the tenant's data keys that are not yet under the current KEK
     * (KEK rotation or provider move). Only the wrapping changes: secrets
     * are not re-encrypted. Idempotent: keys already under the current KEK
     * are skipped. Returns the number of keys re-wrapped.
     */
    public function rewrapDataKeys(?Actor $actor = null): int
    {
        $tenantId = $this->context->requireTenant();
        $current = $this->kms->currentKeyId();

        return DB::transaction(function () use ($actor, $tenantId, $current): int {
            $keys = TenantDataKey::query()->where('kek_id', '<>', $current)->lockForUpdate()->get();
            if ($keys->isEmpty()) {
                return 0;
            }

            $from = $keys->pluck('kek_id')->unique()->values()->all();
            foreach ($keys as $key) {
                $dek = $this->unwrap($key);
                $key->forceFill([
                    'wrapped_key' => $this->kms->wrap($dek, $this->wrapContext($key->tenant_id, $key->version)),
                    'kek_id' => $current,
                ])->save();
            }

            $this->audit->record(AuditAction::DataKeysRewrapped, 'tenant', $tenantId, [
                'keys' => $keys->count(),
                'from_kek_ids' => $from,
                'kek_id' => $current,
            ], $actor);

            return $keys->count();
        });
    }

    /**
     * Unwrap one data key without returning it: proves the configured KEK
     * opens it. Used by vault:verify-kek.
     */
    public function canUnwrap(int $version): bool
    {
        $this->context->requireTenant();
        $key = TenantDataKey::query()->where('version', $version)->first();
        if ($key === null) {
            return false;
        }

        $dek = $this->unwrap($key);
        $ok = strlen($dek) === 32;
        sodium_memzero($dek);

        return $ok;
    }

    /**
     * Non-reversible, keyed fingerprint for display and change detection.
     */
    public function fingerprint(#[\SensitiveParameter] string $secret): string
    {
        $mac = substr(hash_hmac('sha256', $secret, (string) config('platform.vault.fingerprint_key')), 0, 16);
        $tail = strlen($secret) >= 12 ? substr($secret, -4) : '';

        return ($tail !== '' ? '…'.$tail.'#' : '#').$mac;
    }

    private function decrypt(TenantIntegration $integration): string
    {
        if ($integration->encrypted_secret === null) {
            throw new VaultException('Integration has no secret.');
        }

        $envelope = json_decode($integration->encrypted_secret, true);
        if (! is_array($envelope) || ($envelope['v'] ?? null) !== self::ENVELOPE_VERSION) {
            throw new DecryptionFailed;
        }

        $key = TenantDataKey::query()->where('version', (int) $envelope['k'])->first()
            ?? throw new DecryptionFailed;

        return Aes256Gcm::decrypt(
            $this->unwrap($key),
            (string) base64_decode((string) $envelope['n'], true),
            (string) base64_decode((string) $envelope['c'], true),
            (string) base64_decode((string) $envelope['t'], true),
            $this->aad($integration),
        );
    }

    private function createDataKey(int $version, ?Actor $actor = null, bool $audit = true): TenantDataKey
    {
        $tenantId = $this->context->requireTenant();
        $dek = Aes256Gcm::generateKey();

        $key = TenantDataKey::query()->create([
            'version' => $version,
            'wrapped_key' => $this->kms->wrap($dek, $this->wrapContext($tenantId, $version)),
            'kek_id' => $this->kms->currentKeyId(),
            'status' => 'active',
        ]);

        if ($audit) {
            $this->audit->record(AuditAction::DataKeyCreated, 'tenant_data_key', $key->id, ['version' => $version], $actor);
        }

        return $key;
    }

    private function unwrap(TenantDataKey $key): string
    {
        return $this->kms->unwrap($key->wrapped_key, $key->kek_id, $this->wrapContext($key->tenant_id, $key->version));
    }

    private function aad(TenantIntegration $integration): string
    {
        return sprintf(
            'tenant:%s|integration:%s|provider:%s|capability:%s',
            $integration->tenant_id,
            $integration->id,
            $integration->provider,
            $integration->capability,
        );
    }

    private function wrapContext(string $tenantId, int $version): string
    {
        return "tenant:{$tenantId}|dek:v{$version}";
    }

    private function assertSameTenant(TenantIntegration $integration): void
    {
        $tenantId = $this->context->requireTenant();
        $integration->tenant_id ??= $tenantId;

        if ($integration->tenant_id !== $tenantId) {
            throw new TenantMismatch;
        }
    }
}
