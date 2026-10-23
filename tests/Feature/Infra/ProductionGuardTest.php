<?php

namespace Tests\Feature\Infra;

use App\Support\ProductionGuard;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ProductionGuardTest extends TestCase
{
    private const ARN = 'arn:aws:kms:eu-central-1:123456789012:key/1234abcd-12ab-34cd-56ef-1234567890ab';

    private const SECRETS = ['fake-app-key-value', 'fake-fingerprint-key', 'fake-audit-key', 'AKIAFAKEFAKEFAKEFAKE', 'fake-aws-secret-value', 'fake-db-password'];

    /**
     * A complete, safe production configuration.
     *
     * @return array<string, mixed>
     */
    private function production(array $overrides = []): array
    {
        $config = [
            'app' => ['debug' => false, 'key' => 'fake-app-key-value'],
            'session' => ['secure' => true, 'http_only' => true],
            'platform' => [
                'vault' => [
                    'kek_driver' => 'aws_kms',
                    'fingerprint_key' => 'fake-fingerprint-key',
                    'aws_kms' => ['key_id' => self::ARN, 'region' => 'eu-central-1', 'access_key_id' => 'AKIAFAKEFAKEFAKEFAKE', 'secret_access_key' => 'fake-aws-secret-value'],
                ],
                'audit' => ['hash_key' => 'fake-audit-key'],
                'egress_static_ips' => [],
            ],
            'consent' => ['iys' => ['allow_production' => false]],
            'database' => [
                'roles' => ['app' => 'saas_app', 'owner' => 'saas_owner', 'platform' => 'saas_platform'],
                'connections' => [
                    'pgsql' => ['username' => 'saas_app', 'host' => 'ep-quiet-sky-123.eu-central-1.pg.laravel.cloud', 'sslmode' => 'require', 'password' => 'fake-db-password'],
                    'pgsql_owner' => ['username' => 'saas_owner', 'sslmode' => 'require'],
                    'pgsql_platform' => ['username' => 'saas_platform', 'sslmode' => 'require'],
                ],
            ],
        ];

        return array_replace_recursive($config, $overrides);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function problems(array $config, string $env = 'production'): array
    {
        return ProductionGuard::problems(new Repository($config), $env);
    }

    public function test_a_complete_production_configuration_passes(): void
    {
        $this->assertSame([], $this->problems($this->production()));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unsafe(): array
    {
        return [
            'local KMS' => [['platform' => ['vault' => ['kek_driver' => 'local']]], 'local KEK driver is not allowed'],
            'empty KMS driver' => [['platform' => ['vault' => ['kek_driver' => '']]], 'SECRET_VAULT_KEK_DRIVER is empty'],
            'unknown KMS driver' => [['platform' => ['vault' => ['kek_driver' => 'gcp_kms']]], 'not implemented'],
            'KMS key alias' => [['platform' => ['vault' => ['aws_kms' => ['key_id' => 'alias/pratiktools']]]], 'full key ARN'],
            'KMS region mismatch' => [['platform' => ['vault' => ['aws_kms' => ['region' => 'us-east-1']]]], 'region does not match'],
            'KMS credentials missing' => [['platform' => ['vault' => ['aws_kms' => ['secret_access_key' => '']]]], 'SECRET_VAULT_AWS_SECRET_ACCESS_KEY is not set'],
            'debug on' => [['app' => ['debug' => true]], 'APP_DEBUG'],
            'insecure cookie' => [['session' => ['secure' => false]], 'SESSION_SECURE_COOKIE'],
            'runtime uses the administrator' => [['database' => ['connections' => ['pgsql' => ['username' => 'neondb_owner']]]], 'DB_USERNAME must be the runtime app role'],
            'roles not distinct' => [['database' => ['roles' => ['owner' => 'saas_app']]], 'three different roles'],
            'runtime via URL' => [['database' => ['connections' => ['pgsql' => ['url' => 'postgresql://neondb_owner:x@host/db']]]], 'must not be configured from a URL'],
            'transaction pooler' => [['database' => ['connections' => ['pgsql' => ['host' => 'ep-quiet-sky-123-pooler.eu-central-1.pg.laravel.cloud']]]], 'not the transaction pooler'],
            'no TLS to Postgres' => [['database' => ['connections' => ['pgsql' => ['sslmode' => 'prefer']]]], 'DB_SSLMODE'],
            'İYS production without static IPs' => [['consent' => ['iys' => ['allow_production' => true]]], 'EGRESS_STATIC_IPS'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('unsafe')]
    public function test_unsafe_production_settings_are_refused_without_revealing_values(array $overrides, string $expected): void
    {
        $problems = implode('; ', $this->problems($this->production($overrides)));

        $this->assertStringContainsString($expected, $problems);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $problems);
        }
    }

    public function test_local_kms_is_never_allowed_in_production_and_only_explicitly_in_staging(): void
    {
        $local = $this->production(['platform' => ['vault' => ['kek_driver' => 'local', 'allow_local_kek_in_staging' => true]]]);

        $this->assertNotSame([], $this->problems($local, 'production'));
        $this->assertSame([], $this->problems($local, 'staging'));
        $this->assertNotSame([], $this->problems($this->production(['platform' => ['vault' => ['kek_driver' => 'local']]]), 'staging'));
    }

    public function test_the_application_refuses_to_boot_in_production_with_the_local_kms(): void
    {
        $this->app['env'] = 'production';
        config(['platform.vault.kek_driver' => 'local']);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('local KEK driver is not allowed');
            ProductionGuard::assertSafe();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_the_application_refuses_to_boot_in_production_with_an_unknown_kms_driver(): void
    {
        $this->app['env'] = 'production';
        config(['platform.vault.kek_driver' => 'vault_transit']);

        try {
            $this->expectExceptionMessage('not implemented');
            ProductionGuard::assertSafe();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
