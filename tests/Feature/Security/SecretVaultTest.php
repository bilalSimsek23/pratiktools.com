<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\TenantDataKey;
use App\Models\TenantIntegration;
use App\Security\SensitiveValue;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\DecryptionFailed;
use App\Security\Vault\LocalKeyEncryptionService;
use App\Security\Vault\SecretVault;
use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

#[Group('security')]
final class SecretVaultTest extends TestCase
{
    use InteractsWithTenants;

    private const SECRET = 'sk_live_provider_secret_value_42';

    public function test_secret_round_trips_and_is_never_stored_in_plaintext(): void
    {
        [$a] = $this->createTenant('alpha');
        $integration = $this->createIntegration($a, self::SECRET);

        $stored = DB::connection('pgsql_owner')->table('tenant_integrations')->where('id', $integration->id)->value('encrypted_secret');
        $this->assertNull($stored, 'Owner role must not see rows either (FORCE RLS).');

        $envelope = $this->inTenant($a, fn () => DB::selectOne('SELECT encrypted_secret FROM tenant_integrations WHERE id = ?', [$integration->id])->encrypted_secret);
        $this->assertStringNotContainsString(self::SECRET, $envelope);
        $this->assertStringNotContainsString(base64_encode(self::SECRET), $envelope);
        $this->assertSame(1, json_decode($envelope, true)['v']);

        $revealed = $this->inTenant($a, fn () => app(SecretVault::class)->reveal(TenantIntegration::query()->find($integration->id), 'test'));
        $this->assertSame(self::SECRET, $revealed->reveal());
    }

    public function test_each_tenant_has_its_own_data_key_and_app_key_is_not_used(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');

        $keyA = $this->inTenant($a, fn () => TenantDataKey::query()->first());
        $keyB = $this->inTenant($b, fn () => TenantDataKey::query()->first());

        $this->assertNotSame($keyA->wrapped_key, $keyB->wrapped_key);
        $this->assertSame('test-v1', $keyA->kek_id);

        // Changing APP_KEY must not affect secrets.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $integration = $this->createIntegration($a, self::SECRET);
        $this->assertSame(self::SECRET, $this->inTenant($a, fn () => app(SecretVault::class)->reveal($integration, 'test')->reveal()));
    }

    public function test_ciphertext_copied_to_another_record_or_tenant_does_not_decrypt(): void
    {
        [$a] = $this->createTenant('alpha');
        $source = $this->createIntegration($a, self::SECRET, 'klaviyo', 'email');
        $target = $this->createIntegration($a, 'other-secret-value-0001', 'onesignal', 'push');

        $this->inTenant($a, function () use ($source, $target) {
            $envelope = DB::selectOne('SELECT encrypted_secret FROM tenant_integrations WHERE id = ?', [$source->id])->encrypted_secret;
            DB::update('UPDATE tenant_integrations SET encrypted_secret = ? WHERE id = ?', [$envelope, $target->id]);

            $this->expectException(DecryptionFailed::class);
            app(SecretVault::class)->reveal(TenantIntegration::query()->find($target->id), 'test');
        });
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        [$a] = $this->createTenant('alpha');
        $integration = $this->createIntegration($a, self::SECRET);

        $this->inTenant($a, function () use ($integration) {
            $model = TenantIntegration::query()->find($integration->id);
            $envelope = json_decode($model->encrypted_secret, true);
            $bytes = base64_decode($envelope['c']);
            $bytes[0] = chr(ord($bytes[0]) ^ 1);
            $envelope['c'] = base64_encode($bytes);
            $model->encrypted_secret = json_encode($envelope);

            $this->expectException(DecryptionFailed::class);
            app(SecretVault::class)->reveal($model, 'test');
        });
    }

    public function test_vault_refuses_integrations_of_another_tenant(): void
    {
        [$a] = $this->createTenant('alpha');
        [$b] = $this->createTenant('beta');
        $integration = $this->createIntegration($b, self::SECRET);

        $this->expectException(TenantMismatch::class);
        $this->inTenant($a, fn () => app(SecretVault::class)->reveal($integration, 'test'));
    }

    public function test_every_decryption_is_audited_with_its_purpose(): void
    {
        [$a] = $this->createTenant('alpha');
        $integration = $this->createIntegration($a, self::SECRET);

        $this->inTenant($a, fn () => app(SecretVault::class)->reveal($integration, 'connector-sync'));

        $entry = $this->inTenant($a, fn () => AuditLog::query()->where('action', 'secret.decrypted')->sole());
        $this->assertSame('connector-sync', $entry->metadata['purpose']);
        $this->assertSame($integration->id, $entry->resource_id);
        $this->assertStringNotContainsString(self::SECRET, json_encode($entry->metadata));
    }

    public function test_data_key_rotation_reencrypts_secrets(): void
    {
        [$a] = $this->createTenant('alpha');
        $integration = $this->createIntegration($a, self::SECRET);
        $before = $this->inTenant($a, fn () => TenantIntegration::query()->find($integration->id)->encrypted_secret);

        $version = $this->inTenant($a, fn () => app(SecretVault::class)->rotateDataKey());

        $this->assertSame(2, $version);
        $this->inTenant($a, function () use ($integration, $before) {
            $fresh = TenantIntegration::query()->find($integration->id);
            $this->assertNotSame($before, $fresh->encrypted_secret);
            $this->assertSame(2, $fresh->secret_key_version);
            $this->assertSame(self::SECRET, app(SecretVault::class)->reveal($fresh, 'test')->reveal());
            $this->assertSame(['retired', 'active'], TenantDataKey::query()->orderBy('version')->pluck('status')->all());
        });
    }

    public function test_kek_rotation_rewraps_data_keys_and_old_kek_can_be_retired(): void
    {
        [$a] = $this->createTenant('alpha');
        $integration = $this->createIntegration($a, self::SECRET);

        $old = config('platform.vault.local.key');
        $new = base64_encode(random_bytes(32));
        config(['platform.vault.local' => ['key_id' => 'test-v2', 'key' => $new, 'previous' => "test-v1:{$old}"]]);
        $this->app->forgetInstance(KeyEncryptionService::class);
        $this->app->forgetInstance(SecretVault::class);

        $this->assertSame(1, $this->inTenant($a, fn () => app(SecretVault::class)->rewrapDataKeys()));
        $this->assertSame('test-v2', $this->inTenant($a, fn () => TenantDataKey::query()->value('kek_id')));

        // The old KEK is no longer needed.
        $this->app->instance(KeyEncryptionService::class, LocalKeyEncryptionService::fromConfig(['key_id' => 'test-v2', 'key' => $new]));
        $this->app->forgetInstance(SecretVault::class);
        $this->assertSame(self::SECRET, $this->inTenant($a, fn () => app(SecretVault::class)->reveal(TenantIntegration::query()->find($integration->id), 'test')->reveal()));
    }

    public function test_sensitive_value_never_leaks_through_casting_json_or_serialization(): void
    {
        $value = new SensitiveValue(self::SECRET);

        $this->assertSame('[redacted]', (string) $value);
        $this->assertSame('"[redacted]"', json_encode($value));
        $this->assertStringNotContainsString(self::SECRET, print_r($value, true));
        $this->assertStringNotContainsString(self::SECRET, print_r(['nested' => [$value]], true));

        $this->expectException(\LogicException::class);
        serialize($value);
    }
}
