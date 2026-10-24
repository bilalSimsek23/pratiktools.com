<?php

namespace App\Health;

use App\Jobs\PlatformHeartbeat;
use App\Push\PushSendingMode;
use App\Security\Vault\KekMaintenance;
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
 * age and secret vault (KEK) status. Error details are never exposed, only the
 * exception class in logs.
 */
class HealthChecker
{
    private const VAULT_CACHE_KEY = 'health:vault';

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
            'vault' => $this->vault(),
            'onesignal' => $this->onesignal(),
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
     * Secret vault: the current KEK works and every KEK id in use is known
     * to the resolver. Cached briefly (never an unwrap per request);
     * failures are cached for less. Status and reason code only: no KEK,
     * id, fingerprint or key material.
     *
     * @return array<string, mixed>
     */
    protected function vault(): array
    {
        $cached = Cache::get(self::VAULT_CACHE_KEY);
        if (is_array($cached)) {
            return $cached + ['cached' => true];
        }

        try {
            $result = app(KekMaintenance::class)->quickStatus(app()->isProduction());
        } catch (Throwable $e) {
            report($e);
            $result = ['status' => 'fail', 'reason' => KekMaintenance::CURRENT_KEY_FAILED];
        }

        Cache::put(self::VAULT_CACHE_KEY, $result, $result['status'] === 'ok' ? (int) config('platform.health.kms_cache_seconds') : 30);

        return $result + ['cached' => false];
    }

    /**
     * Platform-wide view: how many tenants have a OneSignal integration and
     * how many need attention. Database only, never a provider call; a
     * provider problem is "warn", never "fail" (it must not take the
     * application out of rotation).
     *
     * @return array<string, mixed>
     */
    protected function onesignal(): array
    {
        try {
            $rows = DB::connection('pgsql_platform')->table('tenant_integrations')->where('provider', 'onesignal')
                ->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'warn', 'reason' => 'unavailable'];
        }

        $total = (int) array_sum($rows);
        $attention = $total - (int) ($rows['connected'] ?? 0) - (int) ($rows['pending'] ?? 0);

        return [
            'status' => $total === 0 ? 'not_configured' : ($attention > 0 ? 'warn' : 'ok'),
            'integrations' => $total,
            'needing_attention' => $attention,
            'sending_mode' => PushSendingMode::platform()->value,
        ];
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
