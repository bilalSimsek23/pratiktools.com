<?php

namespace App\Console\Commands;

use App\Commerce\SyncHealth;
use App\Console\Commands\Concerns\FindsCommerceIntegration;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class CommerceStatus extends Command
{
    use FindsCommerceIntegration, RunsInTenantContext;

    protected $signature = 'commerce:status {--tenant= : Tenant slug} {--json}';

    protected $description = 'Sync health of the tenant\'s commerce integration';

    public function handle(SyncHealth $health): int
    {
        $report = $this->inTenant(fn () => $health->for($this->commerceIntegration()));

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line("Status: {$report['status']}".($report['last_error'] ? " – {$report['last_error']}" : ''));
        $this->table(
            ['entity', 'cursor', 'last run', 'mode', 'read', 'ins', 'upd', 'skip', 'api', 'seconds'],
            array_map(fn (string $entity, array $e) => [
                $entity, $e['cursor'] ?? '-', $e['last_run']['status'] ?? '-', $e['last_run']['mode'] ?? '-',
                $e['last_run']['records_read'] ?? '-', $e['last_run']['inserted'] ?? '-', $e['last_run']['updated'] ?? '-',
                $e['last_run']['skipped'] ?? '-', $e['last_run']['api_calls'] ?? '-',
                isset($e['last_run']['duration_ms']) ? round($e['last_run']['duration_ms'] / 1000, 1) : '-',
            ], array_keys($report['entities']), $report['entities']),
        );

        foreach ($report['recent_errors'] as $error) {
            $this->warn("{$error['occurred_at']} [{$error['category']}] {$error['operation']}: {$error['message']}");
        }

        return self::SUCCESS;
    }
}
