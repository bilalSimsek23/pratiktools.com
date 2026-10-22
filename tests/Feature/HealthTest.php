<?php

namespace Tests\Feature;

use App\Health\HealthChecker;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    use InteractsWithTenants;

    public function test_liveness_is_minimal(): void
    {
        $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_reports_components_without_personal_data(): void
    {
        $this->createTenant('alpha');
        $this->fakeHorizon(running: true);

        $response = $this->getJson('/health')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.application.status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.redis.status', 'ok')
            ->assertJsonPath('checks.queue.status', 'ok');
        $this->assertSame(['system', 'webhooks', 'sync', 'automation', 'send'], array_keys($response->json('checks.queue.backlog')));
        $this->assertStringNotContainsString('alpha', $response->getContent());
        $this->assertStringNotContainsString('@', $response->getContent());
    }

    public function test_readiness_fails_when_horizon_is_not_running(): void
    {
        $this->fakeHorizon(running: false);

        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.queue.horizon', 'not_running');
    }

    private function fakeHorizon(bool $running): void
    {
        $this->app->instance(MasterSupervisorRepository::class, new class($running) implements MasterSupervisorRepository
        {
            public function __construct(private bool $running) {}

            public function names()
            {
                return [];
            }

            public function all()
            {
                return $this->running ? [(object) ['status' => 'running']] : [];
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
    }
}
