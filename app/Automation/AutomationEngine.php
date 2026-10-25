<?php

namespace App\Automation;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One bounded tick for the current tenant: scan event triggers, evaluate
 * time triggers, then claim and advance due runs until the batch or the
 * time budget is used up. Remaining work waits for the next tick, so a
 * large tenant cannot starve the others (each tenant has its own job).
 */
final class AutomationEngine
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TriggerScanner $scanner,
        private readonly TimeTriggers $time,
        private readonly RunClaimer $claimer,
        private readonly RunProcessor $processor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function tick(bool $scan = true, ?CarbonImmutable $now = null): array
    {
        $this->context->requireTenant();
        $deadline = microtime(true) + (int) config('automation.tick.time_budget_seconds');
        $result = ['scanned' => [], 'time_triggers' => [], 'processed' => 0, 'outcomes' => []];

        if ($scan) {
            $result['scanned'] = $this->scanner->scan();
            $result['time_triggers'] = $this->time->evaluate($this->scanner);
        }

        $budget = (int) config('automation.tick.runs_per_tick');
        while ($budget > 0 && microtime(true) < $deadline) {
            $claimed = $this->claimer->claim(min(50, $budget), $now);
            if ($claimed === []) {
                break;
            }
            foreach ($claimed as $c) {
                try {
                    $outcome = $this->processor->process($c['id'], $c['claim_token'], $now);
                } catch (LeaseLost) {
                    $outcome = 'lease_lost';
                } catch (Throwable $e) {
                    report($e);
                    $outcome = 'error';
                }
                $result['outcomes'][$outcome] = ($result['outcomes'][$outcome] ?? 0) + 1;
                $result['processed']++;
                $budget--;
            }
        }

        return $result;
    }
}
