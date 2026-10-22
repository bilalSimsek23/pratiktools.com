<?php

namespace App\Health;

use App\Jobs\PlatformHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Infrastructure health without personal data: status and latency per
 * component, queue backlog sizes and whether Horizon is running. Error
 * details are never exposed, only the exception class in logs.
 */
class HealthChecker
{
    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function check(): array
    {
        $checks = [
            'application' => ['status' => 'ok'],
            'database' => $this->timed(fn () => DB::select('SELECT 1')),
            'redis' => $this->timed(fn () => Redis::connection()->ping()),
            'queue' => $this->queue(),
        ];

        $failed = collect($checks)->contains(fn (array $c) => $c['status'] === 'fail');

        return ['status' => $failed ? 'fail' : 'ok', 'checks' => $checks];
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
                'last_heartbeat' => Cache::get(PlatformHeartbeat::CACHE_KEY),
            ];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'fail'];
        }
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
