<?php

namespace App\Health;

use App\Jobs\PlatformHeartbeat;
use App\Security\Vault\Contracts\KeyEncryptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Infrastructure health without personal data or secrets: status and
 * latency per component, the runtime database role and whether it can
 * bypass row-level security, queue backlog, Horizon, scheduler heartbeat
 * age and KMS reachability. Error details are never exposed, only the
 * exception class in logs.
 */
class HealthChecker
{
    private const KMS_CACHE_KEY = 'health:kms';

    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function check(): array
    {
        $checks = [
            'application' => ['status' => 'ok'],
            'database' => $this->database(),
            'redis' => $this->timed(fn () => Redis::connection()->ping()),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'kms' => $this->kms(),
        ];

        $failed = collect($checks)->contains(fn (array $c) => $c['status'] === 'fail');

        return ['status' => $failed ? 'fail' : 'ok', 'checks' => $checks];
    }

    /**
     * The runtime connection must be the configured app role and must not
     * be able to bypass row-level security.
     *
     * @return array<string, mixed>
     */
    protected function database(): array
    {
        $start = hrtime(true);

        try {
            $role = DB::selectOne('SELECT current_user AS role, r.rolbypassrls AS bypass, r.rolsuper AS super FROM pg_roles r WHERE r.rolname = current_user');
            $expected = config('database.roles.app');
            $ok = $role !== null && ! $role->bypass && ! $role->super && $role->role === $expected;

            return [
                'status' => $ok ? 'ok' : 'fail',
                'latency_ms' => round((hrtime(true) - $start) / 1e6, 1),
                'role' => $role->role ?? null,
                'expected_role' => $role?->role === $expected,
                'bypass_rls' => (bool) ($role->bypass ?? true),
                'superuser' => (bool) ($role->super ?? true),
            ];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'fail'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function queue(): array
    {
        try {
            $supervisors = app(MasterSupervisorRepository::class)->all();
            $running = collect($supervisors)->contains(fn ($s) => ($s->status ?? null) === 'running');

            $backlog = [];
            foreach (config('platform.queues') as $queue) {
                $backlog[$queue] = Queue::connection('redis')->size($queue);
            }

            return [
                'status' => $running ? 'ok' : 'fail',
                'horizon' => $running ? 'running' : 'not_running',
                'backlog' => $backlog,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'fail'];
        }
    }

    /**
     * The heartbeat is a scheduled job: a fresh one proves scheduler, queue
     * and worker all run. Missing is "unknown" until the scheduler is meant
     * to run (HEALTH_REQUIRE_HEARTBEAT=true), then it fails.
     *
     * @return array<string, mixed>
     */
    protected function scheduler(): array
    {
        $last = Cache::get(PlatformHeartbeat::CACHE_KEY);
        $maxAge = (int) config('platform.health.heartbeat_max_age_seconds');

        if (! is_string($last)) {
            return ['status' => config('platform.health.require_heartbeat') ? 'fail' : 'unknown', 'last_heartbeat' => null];
        }

        $age = (int) CarbonImmutable::parse($last)->diffInSeconds(now(), true);

        return ['status' => $age <= $maxAge ? 'ok' : 'fail', 'last_heartbeat' => $last, 'age_seconds' => $age];
    }

    /**
     * A wrap/unwrap round trip of a throwaway key. Cached briefly so the
     * endpoint never turns into KMS traffic; failures are cached for less.
     *
     * @return array<string, mixed>
     */
    protected function kms(): array
    {
        $cached = Cache::get(self::KMS_CACHE_KEY);
        if (is_array($cached)) {
            return $cached + ['cached' => true];
        }

        $start = hrtime(true);
        $driver = (string) config('platform.vault.kek_driver');

        try {
            $kms = app(KeyEncryptionService::class);
            $probe = random_bytes(32);
            $ok = hash_equals($probe, $kms->unwrap($kms->wrap($probe, 'health-check'), $kms->currentKeyId(), 'health-check'));
            $result = ['status' => $ok ? 'ok' : 'fail', 'driver' => $driver, 'latency_ms' => round((hrtime(true) - $start) / 1e6, 1)];
        } catch (Throwable $e) {
            report($e);
            $result = ['status' => 'fail', 'driver' => $driver];
        }

        Cache::put(self::KMS_CACHE_KEY, $result, $result['status'] === 'ok' ? (int) config('platform.health.kms_cache_seconds') : 30);

        return $result + ['cached' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function timed(callable $probe): array
    {
        $start = hrtime(true);

        try {
            $probe();

            return ['status' => 'ok', 'latency_ms' => round((hrtime(true) - $start) / 1e6, 1)];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'fail'];
        }
    }
}
