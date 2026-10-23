<?php

namespace Tests\Feature\Infra;

use App\Health\HealthChecker;
use App\Jobs\PlatformHeartbeat;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\KmsUnavailable;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Tests\TestCase;

final class HealthHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MasterSupervisorRepository::class, new class implements MasterSupervisorRepository
        {
            public function names()
            {
                return [];
            }

            public function all()
            {
                return [(object) ['status' => 'running']];
            }

            public function find($name)
            {
                return null;
            }

            public function get(array $names)
            {
                return [];
            }

            public function update(MasterSupervisor $master) {}

            public function forget($name) {}

            public function flushExpired() {}
        });
        $this->app->forgetInstance(HealthChecker::class);
        Cache::forget('health:kms');
    }

    public function test_readiness_reports_role_rls_kms_and_scheduler_without_secrets(): void
    {
        Cache::put(PlatformHeartbeat::CACHE_KEY, now()->subSeconds(30)->toIso8601String(), 600);

        $response = $this->getJson('/health')->assertOk();

        $response->assertJsonPath('checks.database.role', 'saas_app')
            ->assertJsonPath('checks.database.bypass_rls', false)
            ->assertJsonPath('checks.database.superuser', false)
            ->assertJsonPath('checks.kms.status', 'ok')
            ->assertJsonPath('checks.kms.driver', 'local')
            ->assertJsonPath('checks.scheduler.status', 'ok');

        $body = $response->getContent();
        foreach ([config('database.connections.pgsql.password'), config('app.key'), config('platform.vault.local.key'), config('platform.vault.fingerprint_key'), config('platform.audit.hash_key'), config('database.connections.pgsql.host')] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
        $this->assertStringNotContainsString('@', $body);
    }

    public function test_kms_probe_is_cached(): void
    {
        $calls = 0;
        $real = app(KeyEncryptionService::class);
        $this->app->instance(KeyEncryptionService::class, new class($real, $calls) implements KeyEncryptionService
        {
            public function __construct(private KeyEncryptionService $inner, private int &$calls) {}

            public function currentKeyId(): string
            {
                return $this->inner->currentKeyId();
            }

            public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
            {
                $this->calls++;

                return $this->inner->wrap($dataKey, $context);
            }

            public function unwrap(string $wrappedKey, string $keyId, string $context): string
            {
                return $this->inner->unwrap($wrappedKey, $keyId, $context);
            }
        });

        $this->getJson('/health')->assertJsonPath('checks.kms.cached', false);
        $this->getJson('/health')->assertJsonPath('checks.kms.cached', true);
        $this->assertSame(1, $calls);
    }

    public function test_unreachable_kms_fails_readiness(): void
    {
        $this->app->instance(KeyEncryptionService::class, new class implements KeyEncryptionService
        {
            public function currentKeyId(): string
            {
                return 'k';
            }

            public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
            {
                throw new KmsUnavailable('AWS KMS encrypt failed temporarily (ThrottlingException).');
            }

            public function unwrap(string $wrappedKey, string $keyId, string $context): string
            {
                return '';
            }
        });

        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.kms.status', 'fail');
    }

    public function test_wrong_runtime_role_fails_readiness(): void
    {
        config(['database.roles.app' => 'some_other_role']);

        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.database.status', 'fail')->assertJsonPath('checks.database.expected_role', false);
    }

    public function test_heartbeat_age_rules(): void
    {
        Cache::forget(PlatformHeartbeat::CACHE_KEY);
        $this->getJson('/health')->assertJsonPath('checks.scheduler.status', 'unknown');

        config(['platform.health.require_heartbeat' => true]);
        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.scheduler.status', 'fail');

        Cache::put(PlatformHeartbeat::CACHE_KEY, now()->subMinutes(10)->toIso8601String(), 600);
        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.scheduler.status', 'fail');
    }
}
