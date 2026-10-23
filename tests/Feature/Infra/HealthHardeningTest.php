<?php

namespace Tests\Feature\Infra;

use App\Health\HealthChecker;
use App\Jobs\PlatformHeartbeat;
use App\Security\Vault\Contracts\KeyEncryptionService;
use App\Security\Vault\Exceptions\VaultException;
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
        Cache::forget('health:vault');
    }

    public function test_readiness_reports_role_rls_kms_and_scheduler_without_secrets(): void
    {
        Cache::put(PlatformHeartbeat::CACHE_KEY, now()->subSeconds(30)->toIso8601String(), 600);

        $response = $this->getJson('/health')->assertOk();

        $response->assertJsonPath('checks.database.role', 'saas_app')
            ->assertJsonPath('checks.database.bypass_rls', false)
            ->assertJsonPath('checks.database.superuser', false)
            ->assertJsonPath('checks.vault.status', 'ok')
            ->assertJsonPath('checks.vault.reason', 'bootstrap_no_data_keys')
            ->assertJsonPath('checks.scheduler.status', 'ok');

        $this->assertSame(['status', 'reason', 'cached'], array_keys($response->json('checks.vault')));
        $body = $response->getContent();
        foreach ([config('database.connections.pgsql.password'), config('app.key'), config('platform.vault.local.key'), config('platform.vault.local.key_id'), config('platform.vault.fingerprint_key'), config('platform.audit.hash_key'), config('database.connections.pgsql.host')] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
        $this->assertStringNotContainsString('@', $body);
    }

    public function test_vault_probe_is_cached(): void
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

            public function knows(string $kekId): bool
            {
                return $this->inner->knows($kekId);
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

        $this->getJson('/health')->assertJsonPath('checks.vault.cached', false);
        $this->getJson('/health')->assertJsonPath('checks.vault.cached', true);
        $this->assertSame(1, $calls);
    }

    public function test_broken_current_kek_fails_readiness(): void
    {
        $this->app->instance(KeyEncryptionService::class, new class implements KeyEncryptionService
        {
            public function currentKeyId(): string
            {
                return 'cs-0000000000000000';
            }

            public function knows(string $kekId): bool
            {
                return false;
            }

            public function wrap(#[\SensitiveParameter] string $dataKey, string $context): string
            {
                throw new VaultException('broken');
            }

            public function unwrap(string $wrappedKey, string $keyId, string $context): string
            {
                return '';
            }
        });

        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.vault.status', 'fail')->assertJsonPath('checks.vault.reason', 'current_key_failed');
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
