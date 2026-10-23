<?php

namespace App\Support;

use App\Security\Vault\AwsKmsKeyEncryptionService;
use App\Security\Vault\KeyEncryptionServiceFactory;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Refuses to boot staging/production with unsafe settings. Messages name
 * the setting, never its value.
 */
final class ProductionGuard
{
    /**
     * Build-time commands that never serve traffic (composer install runs
     * package:discover before any environment file exists).
     */
    private const BUILD_COMMANDS = ['package:discover', 'vendor:publish', 'list', 'help', 'about', 'env'];

    private const SAFE_SSL_MODES = ['require', 'verify-ca', 'verify-full'];

    public static function assertSafe(): void
    {
        if (! app()->environment(['production', 'staging']) || self::isBuildCommand()) {
            return;
        }

        $problems = self::problems(config(), (string) app()->environment());

        if ($problems !== []) {
            throw new RuntimeException('Unsafe configuration: '.implode('; ', $problems).'.');
        }
    }

    /**
     * @return list<string>
     */
    public static function problems(Repository $config, string $environment): array
    {
        $problems = [];
        $production = $environment === 'production';

        if ($config->get('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }
        if ($config->get('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true';
        }
        if ($config->get('session.http_only') !== true) {
            $problems[] = 'SESSION_HTTP_ONLY must be true';
        }
        foreach (['platform.vault.fingerprint_key' => 'SECRET_VAULT_FINGERPRINT_KEY', 'platform.audit.hash_key' => 'AUDIT_HASH_KEY', 'app.key' => 'APP_KEY'] as $key => $env) {
            if (empty($config->get($key))) {
                $problems[] = "{$env} is not set";
            }
        }

        array_push($problems, ...self::kmsProblems($config, $production));
        array_push($problems, ...self::databaseProblems($config));

        // İYS production accepts only registered IPs: never without static egress.
        if ($config->get('consent.iys.allow_production') && $config->get('platform.egress_static_ips', []) === []) {
            $problems[] = 'IYS_ALLOW_PRODUCTION requires EGRESS_STATIC_IPS (registered static outbound IPs)';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function kmsProblems(Repository $config, bool $production): array
    {
        $driver = (string) $config->get('platform.vault.kek_driver', '');

        if ($driver === '') {
            return ['SECRET_VAULT_KEK_DRIVER is empty; configure a KMS driver'];
        }
        if (! in_array($driver, KeyEncryptionServiceFactory::implemented(), true)) {
            return ['SECRET_VAULT_KEK_DRIVER names a driver that is not implemented'];
        }
        if ($driver === 'local') {
            return $production || ! $config->get('platform.vault.allow_local_kek_in_staging')
                ? ['the local KEK driver is not allowed; configure a KMS driver (aws_kms)']
                : [];
        }
        if ($production && ! in_array($driver, KeyEncryptionServiceFactory::productionDrivers(), true)) {
            return ['SECRET_VAULT_KEK_DRIVER is not a production KMS driver'];
        }

        // Per-driver settings checks; a new driver adds its own entry.
        $settings = ['aws_kms' => fn () => self::awsKmsProblems((array) $config->get('platform.vault.aws_kms', []))];

        return isset($settings[$driver]) ? $settings[$driver]() : [];
    }

    /**
     * @param  array<string, mixed>  $aws
     * @return list<string>
     */
    private static function awsKmsProblems(array $aws): array
    {
        $problems = [];
        foreach (['key_id' => 'SECRET_VAULT_AWS_KMS_KEY_ID', 'region' => 'SECRET_VAULT_AWS_REGION', 'access_key_id' => 'SECRET_VAULT_AWS_ACCESS_KEY_ID', 'secret_access_key' => 'SECRET_VAULT_AWS_SECRET_ACCESS_KEY'] as $key => $env) {
            if (empty($aws[$key])) {
                $problems[] = "{$env} is not set";
            }
        }
        if (! empty($aws['key_id'])) {
            if (! preg_match(AwsKmsKeyEncryptionService::KEY_ARN_PATTERN, (string) $aws['key_id'], $m)) {
                $problems[] = 'SECRET_VAULT_AWS_KMS_KEY_ID must be a full key ARN, not an alias';
            } elseif (! empty($aws['region']) && $m[2] !== $aws['region']) {
                $problems[] = 'SECRET_VAULT_AWS_KMS_KEY_ID region does not match SECRET_VAULT_AWS_REGION';
            }
        }

        return $problems;
    }

    /**
     * The runtime connection must be the RLS-bound app role, separate from
     * the owner and platform roles, and must not go through a transaction
     * pooler (the tenant context is a session setting).
     *
     * @return list<string>
     */
    private static function databaseProblems(Repository $config): array
    {
        $problems = [];
        $app = (array) $config->get('database.connections.pgsql', []);
        $roles = (array) $config->get('database.roles', []);

        if (! empty($app['url'])) {
            $problems[] = 'the runtime connection must not be configured from a URL';
        }
        if (($app['username'] ?? null) !== ($roles['app'] ?? null)) {
            $problems[] = 'DB_USERNAME must be the runtime app role';
        }
        if (count(array_unique(array_filter([$roles['app'] ?? null, $roles['owner'] ?? null, $roles['platform'] ?? null]))) !== 3) {
            $problems[] = 'DB_USERNAME, DB_OWNER_USERNAME and DB_PLATFORM_USERNAME must be three different roles';
        }
        if (str_contains((string) ($app['host'] ?? ''), '-pooler')) {
            $problems[] = 'DB_HOST must be the direct endpoint, not the transaction pooler (tenant context is a session setting)';
        }
        foreach (['pgsql' => 'DB_SSLMODE', 'pgsql_owner' => 'DB_SSLMODE', 'pgsql_platform' => 'DB_SSLMODE'] as $connection => $env) {
            if (! in_array($config->get("database.connections.{$connection}.sslmode"), self::SAFE_SSL_MODES, true)) {
                $problems[] = "{$env} must be require or verify-full";
                break;
            }
        }

        return $problems;
    }

    private static function isBuildCommand(): bool
    {
        return app()->runningInConsole() && in_array($_SERVER['argv'][1] ?? null, self::BUILD_COMMANDS, true);
    }
}
