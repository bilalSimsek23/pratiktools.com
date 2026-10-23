<?php

namespace Tests\Feature\Infra;

use App\Logging\UseStructuredJson;
use Illuminate\Redis\RedisManager;
use Predis\Client;
use Tests\TestCase;

/**
 * Production configuration read from the real config files with
 * Laravel-Cloud-like environment variables (fake values, no network).
 */
final class ProductionConfigTest extends TestCase
{
    /** @var array<string, array{0: string|null, 1: string|false}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $putenv]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($env !== null) {
                $_ENV[$key] = $_SERVER[$key] = $env;
            }
            putenv($putenv === false ? $key : "{$key}={$putenv}");
        }
        parent::tearDown();
    }

    /**
     * @param  array<string, string|null>  $vars  null = variable not defined
     * @return array<string, mixed>
     */
    private function configFile(string $file, array $vars): array
    {
        foreach ($vars as $key => $value) {
            $this->saved[$key] ??= [$_ENV[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($value !== null) {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        return require base_path("config/{$file}.php");
    }

    private function predisScheme(array $connection): string
    {
        $manager = new RedisManager($this->app, 'predis', ['options' => ['prefix' => 't:'], 'probe' => $connection]);
        /** @var Client $client */
        $client = $manager->connection('probe')->client();

        return (string) $client->getConnection()->getParameters()->scheme;
    }

    public function test_valkey_tls_from_rediss_url(): void
    {
        $redis = $this->configFile('database', ['REDIS_URL' => 'rediss://default:fake-password@valkey-abc.eu-central-1.cache.laravel.cloud:6379'])['redis'];

        $this->assertSame('tls', $this->predisScheme($redis['default']));
        $this->assertSame('tls', $this->predisScheme($redis['cache']));
    }

    public function test_valkey_tls_from_host_and_scheme_variables(): void
    {
        $redis = $this->configFile('database', [
            'REDIS_URL' => null, 'REDIS_HOST' => 'valkey-abc.eu-central-1.cache.laravel.cloud', 'REDIS_PORT' => '6379',
            'REDIS_USERNAME' => 'default', 'REDIS_PASSWORD' => 'fake-password', 'REDIS_SCHEME' => 'tls',
        ])['redis'];

        $this->assertSame('tls', $this->predisScheme($redis['default']));
        $this->assertSame('default', $redis['default']['username']);
    }

    public function test_local_redis_stays_plain_tcp(): void
    {
        $redis = $this->configFile('database', ['REDIS_URL' => null, 'REDIS_SCHEME' => null, 'REDIS_HOST' => '127.0.0.1'])['redis'];

        $this->assertSame('tcp', $this->predisScheme($redis['default']));
    }

    public function test_an_injected_database_url_cannot_replace_the_runtime_role(): void
    {
        $db = $this->configFile('database', [
            'DB_URL' => 'postgresql://neondb_owner:fake-admin-password@ep-x.eu-central-1.pg.laravel.cloud/neondb',
            'DATABASE_URL' => 'postgresql://neondb_owner:fake-admin-password@ep-x.eu-central-1.pg.laravel.cloud/neondb',
            'DB_USERNAME' => 'saas_app',
        ]);

        $this->assertArrayNotHasKey('url', $db['connections']['pgsql']);
        $this->assertArrayNotHasKey('url', $db['connections']['pgsql_owner']);
        $this->assertArrayNotHasKey('url', $db['connections']['pgsql_platform']);
        $this->assertSame('saas_app', $db['connections']['pgsql']['username']);
        $this->assertSame('saas_app', $db['roles']['app']);
    }

    public function test_production_logs_go_to_stderr_as_structured_json(): void
    {
        $logging = $this->configFile('logging', ['APP_ENV' => 'production', 'LOG_CHANNEL' => null]);
        $this->assertSame('json_stderr', $logging['default']);
        $this->assertSame('json', $this->configFile('logging', ['APP_ENV' => 'local', 'LOG_CHANNEL' => null])['default']);

        $this->assertSame('php://stderr', $logging['channels']['json_stderr']['handler_with']['stream']);
        $this->assertSame([UseStructuredJson::class], $logging['channels']['json_stderr']['tap']);
    }
}
