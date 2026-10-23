<?php

namespace Tests\Feature\Security;

use App\Audit\Actor;
use App\Logging\Redactor;
use App\Models\Tenant;
use App\Models\TenantDataKey;
use App\Models\TenantIntegration;
use App\Security\Vault\AwsKmsKeyEncryptionService;
use App\Security\Vault\CloudSecretKeyEncryptionService;
use App\Security\Vault\CompositeKeyEncryptionService;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;
use App\Security\Vault\KekMaintenance;
use App\Security\Vault\KekPolicy;
use App\Security\Vault\KeyEncryptionServiceFactory;
use App\Security\Vault\SecretVault;
use App\Security\Vault\SymmetricKeyring;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class CloudSecretKekTest extends TestCase
{
    use InteractsWithTenants;

    private static function key(): string
    {
        do {
            $raw = random_bytes(32);
        } while (KekPolicy::lowEntropy($raw));

        return base64_encode($raw);
    }

    private static function id(string $b64): string
    {
        return SymmetricKeyring::deriveId('cs-', (string) base64_decode($b64));
    }

    private function useCloudKek(string $current, string $previous = ''): void
    {
        config([
            'platform.vault.kek_driver' => 'cloud_secret',
            'platform.vault.cloud_secret' => ['key' => $current, 'previous' => $previous],
            // Production-like: no local key to fall back on.
            'platform.vault.local.key' => null,
        ]);
        $this->app->forgetInstance(KeyEncryptionService::class);
    }

    /**
     * @return array{0: Tenant, 1: TenantIntegration}
     */
    private function tenantWithSecret(string $slug, string $secret): array
    {
        [$tenant] = $this->createTenant($slug);

        return [$tenant, $this->createIntegration($tenant, $secret)];
    }

    private function ciphertext(Tenant $tenant, TenantIntegration $integration): ?string
    {
        return $this->inTenant($tenant, fn () => $integration->fresh()?->encrypted_secret);
    }

    private function reveal(Tenant $tenant, TenantIntegration $integration): string
    {
        return $this->inTenant($tenant, fn () => app(SecretVault::class)->reveal($integration->fresh(), 'test')->reveal());
    }

    public function test_cloud_secret_driver_wraps_with_a_derived_cs_id(): void
    {
        $k1 = self::key();
        $this->useCloudKek($k1);
        [$tenant, $integration] = $this->tenantWithSecret('alpha', 'ticimax-member-code-0001');

        $this->assertSame(self::id($k1), $this->inTenant($tenant, fn () => TenantDataKey::query()->value('kek_id')));
        $this->assertSame('ticimax-member-code-0001', $this->reveal($tenant, $integration));
    }

    public function test_rotation_rewraps_every_tenant_without_reencrypting_secrets(): void
    {
        $k1 = self::key();
        $k2 = self::key();
        $this->useCloudKek($k1);
        [$a, $ia] = $this->tenantWithSecret('alpha', 'alpha-secret-value-0001');
        [$b, $ib] = $this->tenantWithSecret('beta', 'beta-secret-value-00002');
        $ciphertexts = [$this->ciphertext($a, $ia), $this->ciphertext($b, $ib)];

        // current → previous, new → current.
        $this->useCloudKek($k2, $k1);
        $this->assertSame('alpha-secret-value-0001', $this->reveal($a, $ia), 'Previous key still decrypts.');
        $this->assertSame(0, Artisan::call('vault:verify-kek'));

        $this->assertSame(0, Artisan::call('vault:rewrap', ['--all' => true]));
        $this->assertStringContainsString('2 data key(s) re-wrapped in 2 tenant(s)', Artisan::output());

        $inventory = app(KekMaintenance::class)->inventory();
        $this->assertSame([self::id($k2)], array_column($inventory, 'kek_id'));
        $this->assertSame($ciphertexts, [$this->ciphertext($a, $ia), $this->ciphertext($b, $ib)], 'Secrets are not re-encrypted.');
        $this->assertSame(1, $this->inTenant($a, fn () => DB::table('audit_logs')->where('action', 'vault.data_keys_rewrapped')->count()));

        // Previous removed: everything still opens.
        $this->useCloudKek($k2);
        $this->assertSame('beta-secret-value-00002', $this->reveal($b, $ib));
        $this->assertSame(0, Artisan::call('vault:verify-kek'));
        $this->assertSame(0, Artisan::call('vault:rewrap', ['--all' => true]));
        $this->assertStringContainsString('0 data key(s) re-wrapped in 0 tenant(s)', Artisan::output(), 'Idempotent.');
    }

    public function test_an_interrupted_rewrap_resumes_where_it_stopped(): void
    {
        $k1 = self::key();
        $k2 = self::key();
        $this->useCloudKek($k1);
        [$a] = $this->tenantWithSecret('alpha', 'alpha-secret-value-0001');
        [$b, $ib] = $this->tenantWithSecret('beta', 'beta-secret-value-00002');
        $this->useCloudKek($k2, $k1);

        // The worker dies while tenant beta is being re-wrapped.
        $real = app(KeyEncryptionService::class);
        $this->app->instance(KeyEncryptionService::class, new class($real, $b->id) implements KeyEncryptionService
        {
            public function __construct(private KeyEncryptionService $inner, private string $failTenant) {}

            public function currentKeyId(): string
            {
                return $this->inner->currentKeyId();
            }

            public function knows(string $kekId): bool
            {
                return $this->inner->knows($kekId);
            }

            public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
            {
                if (str_contains($context, $this->failTenant)) {
                    throw new VaultException('simulated crash');
                }

                return $this->inner->wrap($dataKey, $context);
            }

            public function unwrap(string $wrappedKey, string $keyId, string $context): string
            {
                return $this->inner->unwrap($wrappedKey, $keyId, $context);
            }
        });

        try {
            app(KekMaintenance::class)->rewrapAll(Actor::system());
            $this->fail('Expected the simulated crash.');
        } catch (VaultException) {
        }
        $partial = collect(app(KekMaintenance::class)->inventory())->pluck('data_keys', 'kek_id')->sortKeys()->all();
        $this->assertSame(collect([self::id($k1) => 1, self::id($k2) => 1])->sortKeys()->all(), $partial);
        $this->assertSame(self::id($k2), $this->inTenant($a, fn () => TenantDataKey::query()->value('kek_id')), 'Alpha committed.');
        $this->assertSame(self::id($k1), $this->inTenant($b, fn () => TenantDataKey::query()->value('kek_id')), 'Beta rolled back, untouched.');

        $this->app->instance(KeyEncryptionService::class, $real);
        $this->assertSame(['tenants' => 1, 'keys' => 1], app(KekMaintenance::class)->rewrapAll(Actor::system()));
        $this->assertSame([self::id($k2)], array_column(app(KekMaintenance::class)->inventory(), 'kek_id'));
        $this->assertSame('beta-secret-value-00002', $this->reveal($b, $ib));
    }

    public function test_verify_detects_a_missing_previous_key_and_stops_the_deploy(): void
    {
        $k1 = self::key();
        $this->useCloudKek($k1);
        $this->tenantWithSecret('alpha', 'alpha-secret-value-0001');

        // Rotated, but the operator forgot SECRET_VAULT_KEK_PREVIOUS.
        $this->useCloudKek(self::key());

        $this->assertSame(1, Artisan::call('vault:verify-kek'));
        $output = Artisan::output();
        $this->assertStringContainsString('unknown_kek_in_use', $output);
        $this->assertStringContainsString(self::id($k1), $output);
        $this->assertStringNotContainsString($k1, $output);
    }

    public function test_verify_detects_a_key_that_does_not_open_its_data_keys(): void
    {
        $k1 = self::key();
        $k2 = self::key();
        $this->useCloudKek($k1);
        [$a] = $this->tenantWithSecret('alpha', 'alpha-secret-value-0001');

        // Data keys labelled for K2 but wrapped with K1 (corruption / manual edit).
        $this->inTenant($a, fn () => DB::table('tenant_data_keys')->update(['kek_id' => self::id($k2)]));
        $this->useCloudKek($k2);

        $this->assertSame(1, Artisan::call('vault:verify-kek'));
        $this->assertStringContainsString('kek_does_not_open_data_key', Artisan::output());
    }

    public function test_verify_refuses_local_keys_in_production_and_passes_on_an_empty_database(): void
    {
        $this->assertSame(0, Artisan::call('vault:verify-kek'));
        $this->assertStringContainsString('bootstrap_no_data_keys', Artisan::output());

        $this->createTenant('alpha'); // phpunit runs the local driver.
        $result = app(KekMaintenance::class)->verify(production: true);

        $this->assertFalse($result['ok']);
        $this->assertContains('local_kek_in_production', array_column($result['rows'], 'result'));
    }

    public function test_verify_output_contains_no_secret_or_key_material(): void
    {
        $k1 = self::key();
        $this->useCloudKek($k1);
        [$a] = $this->tenantWithSecret('alpha', 'alpha-secret-value-0001');
        $wrapped = $this->inTenant($a, fn () => TenantDataKey::query()->value('wrapped_key'));

        Artisan::call('vault:verify-kek');
        $output = Artisan::output();

        foreach ([$k1, bin2hex((string) base64_decode($k1)), $wrapped, 'alpha-secret-value-0001'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_production_factory_never_loads_the_local_driver(): void
    {
        $vault = config('platform.vault');

        $this->expectException(VaultException::class);
        KeyEncryptionServiceFactory::make(['kek_driver' => 'local'] + $vault, production: true);
    }

    public function test_composite_resolution_routes_aws_ids_to_kms_and_cs_ids_to_the_keyring(): void
    {
        $arn = 'arn:aws:kms:eu-central-1:123456789012:key/1234abcd-12ab-34cd-56ef-1234567890ab';
        $store = [];
        $aws = AwsKmsKeyEncryptionService::fromConfig(['key_id' => $arn, 'region' => 'eu-central-1', 'access_key_id' => 'AKIAFAKEFAKEFAKEFAKE', 'secret_access_key' => 'fake', 'max_attempts' => 1],
            function (CommandInterface $c, RequestInterface $r) use (&$store) {
                if ($c->getName() === 'Encrypt') {
                    $blob = 'b'.count($store);
                    $store[$blob] = $c['Plaintext'];

                    return Create::promiseFor(new Result(['CiphertextBlob' => $blob]));
                }

                return Create::promiseFor(new Result(['Plaintext' => $store[$c['CiphertextBlob']]]));
            });
        $cloud = CloudSecretKeyEncryptionService::fromConfig(['key' => self::key()]);
        $dek = random_bytes(32);

        // Today: cloud secret current; later: AWS current with the cloud keyring kept for unwrapping.
        $wrappedByCloud = $cloud->wrap($dek, 'ctx');
        $composite = new CompositeKeyEncryptionService($aws, [$cloud]);

        $this->assertSame($arn, $composite->currentKeyId());
        $this->assertTrue($composite->knows($cloud->currentKeyId()));
        $this->assertTrue($composite->knows($arn));
        $this->assertFalse($composite->knows('local-v1'));
        $this->assertSame($dek, $composite->unwrap($wrappedByCloud, $cloud->currentKeyId(), 'ctx'));
        $this->assertSame($dek, $composite->unwrap($composite->wrap($dek, 'ctx'), $arn, 'ctx'));
    }

    public function test_generate_kek_prints_a_valid_key_once_and_is_refused_in_deployed_environments(): void
    {
        $this->assertSame(0, Artisan::call('vault:generate-kek'));
        $output = Artisan::output();
        preg_match('/SECRET_VAULT_KEK=(\S+)/', $output, $key);
        preg_match('/kek_id=(cs-[0-9a-f]{16})/', $output, $id);

        $this->assertSame([], KekPolicy::problems(['key' => $key[1]], []));
        $this->assertSame(self::id($key[1]), $id[1]);
        $this->assertStringNotContainsString($key[1], (string) @file_get_contents(storage_path('logs/app.log')));

        $this->app['env'] = 'production';
        try {
            $this->assertSame(1, Artisan::call('vault:generate-kek'));
            $this->assertStringNotContainsString('SECRET_VAULT_KEK=', Artisan::output());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_logs_redact_vault_variables_and_their_values(): void
    {
        $k1 = self::key();
        $this->useCloudKek($k1);
        $this->app->forgetInstance(Redactor::class);
        $redactor = app(Redactor::class);

        $clean = $redactor->redact([
            'SECRET_VAULT_KEK' => $k1,
            'SECRET_VAULT_KEK_PREVIOUS' => 'x',
            'SECRET_VAULT_FINGERPRINT_KEY' => 'x',
            'APP_KEY' => 'base64:x',
            'AUDIT_HASH_KEY' => 'x',
            'env_dump' => ['SECRET_VAULT_KEK' => $k1, 'DB_PASSWORD' => 'x'],
            'message' => "boot failed with KEK {$k1} and APP_KEY ".config('app.key'),
        ]);

        $flat = json_encode($clean);
        $this->assertStringNotContainsString($k1, $flat);
        $this->assertStringNotContainsString(str_replace('base64:', '', (string) config('app.key')), $flat);
        foreach (['SECRET_VAULT_KEK', 'SECRET_VAULT_KEK_PREVIOUS', 'SECRET_VAULT_FINGERPRINT_KEY', 'APP_KEY', 'AUDIT_HASH_KEY'] as $name) {
            $this->assertSame(Redactor::REDACTED, $clean[$name], $name);
        }
        $this->assertSame(Redactor::REDACTED, $clean['env_dump']['SECRET_VAULT_KEK']);
    }
}
