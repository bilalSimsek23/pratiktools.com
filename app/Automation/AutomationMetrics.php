<?php

namespace App\Automation;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant, per-automation daily counters (no personal data). Waiting
 * runs are a live count from automation_runs.
 */
final class AutomationMetrics
{
    public const COUNTERS = ['runs_started', 'runs_completed', 'runs_exited', 'runs_failed', 'runs_cancelled', 'planned_email', 'planned_push', 'consent_blocked',
        'suppression_blocked', 'frequency_capped', 'identity_blocked', 'duplicate_prevented', 'steps_processed', 'latency_ms_sum', 'latency_count'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, int>  $counts
     */
    public function add(string $automationId, array $counts): void
    {
        $counts = array_intersect_key(array_filter($counts), array_flip(self::COUNTERS));
        if ($counts === []) {
            return;
        }
        $cols = array_keys($counts);
        DB::statement(
            'INSERT INTO automation_metrics_daily (tenant_id, automation_id, day, '.implode(', ', $cols).') VALUES (?, ?, current_date, '.implode(', ', array_fill(0, count($cols), '?')).')
             ON CONFLICT (tenant_id, automation_id, day) DO UPDATE SET '.implode(', ', array_map(fn ($c) => "{$c} = automation_metrics_daily.{$c} + EXCLUDED.{$c}", $cols)),
            [$this->context->requireTenant(), $automationId, ...array_values($counts)],
        );
    }

    public static function categoryCounter(?string $category): ?string
    {
        return match ($category) {
            'consent' => 'consent_blocked',
            'suppression' => 'suppression_blocked',
            'frequency' => 'frequency_capped',
            'identity' => 'identity_blocked',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function report(?string $automationId = null, int $days = 30): array
    {
        $tenantId = $this->context->requireTenant();
        $scope = fn ($q) => $automationId === null ? $q : $q->where('automation_id', $automationId);
        $sums = $scope(DB::table('automation_metrics_daily')->where('tenant_id', $tenantId)->where('day', '>=', now()->subDays($days)->toDateString()))
            ->selectRaw(implode(', ', array_map(fn ($c) => "coalesce(sum({$c}), 0) AS {$c}", self::COUNTERS)))->first();
        $totals = array_map('intval', (array) $sums);
        $runs = $scope(DB::table('automation_runs')->where('tenant_id', $tenantId));

        return [
            'days' => $days,
            'counters' => array_diff_key($totals, ['latency_ms_sum' => 1, 'latency_count' => 1]),
            'avg_processing_latency_ms' => $totals['latency_count'] > 0 ? intdiv($totals['latency_ms_sum'], $totals['latency_count']) : null,
            'runs_by_status' => (clone $runs)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'runs_waiting' => (clone $runs)->where('status', 'waiting')->count(),
            'exit_reasons' => (clone $runs)->whereNotNull('exit_reason')->selectRaw('exit_reason, count(*) AS n')->groupBy('exit_reason')->orderByDesc('n')->limit(20)->pluck('n', 'exit_reason')->map(fn ($n) => (int) $n)->all(),
        ];
    }
}
