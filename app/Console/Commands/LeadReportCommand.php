<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Leads\LeadReport;
use Illuminate\Console\Command;

final class LeadReportCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'lead:report {--tenant=} {--days=30} {--form=}';

    protected $description = 'Aggregate lead capture report (no addresses)';

    public function handle(LeadReport $report): int
    {
        return $this->inTenant(function () use ($report) {
            $this->line(json_encode($report->report((int) $this->option('days'), $this->option('form') ?: null), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        });
    }
}
