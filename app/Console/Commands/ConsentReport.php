<?php

namespace App\Console\Commands;

use App\Consent\ConsentReports;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

/**
 * The F2 shadow report: counts only, no addresses.
 */
final class ConsentReport extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:report {--tenant= : Tenant slug} {--json}';

    protected $description = 'Consent shadow reconciliation, newsletter analysis, send eligibility and shadow audience';

    public function handle(ConsentReports $reports): int
    {
        $report = $this->inTenant(function () use ($reports) {
            $report = [
                'generated_at' => now()->toIso8601String(),
                'conflicts' => $reports->refreshConflicts(),
                'summary' => $reports->summary(),
                'matrix' => $reports->matrix(),
                'reconciliation' => $reports->reconciliation(),
                'newsletter' => $reports->newsletter(),
                'shadow_audience' => $reports->shadowAudience(),
            ];
            $reports->snapshot('f2_shadow', $report);

            return $report;
        });

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('F2 summary');
        $this->table(['metric', 'count'], collect($report['summary'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info('Ticimax vs İYS');
        $this->table(['group', 'description', 'count', 'base', '%'], collect($report['matrix'])->map(fn ($c, $k) => [$k, $c['label'], $c['count'], $c['base'], $c['percentage']])->values()->all());
        $this->info('Reconciliation');
        $this->table(['class', 'count'], collect($report['reconciliation'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info('Newsletter');
        $this->table(['metric', 'count'], collect($report['newsletter'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info('Shadow audience: '.$report['shadow_audience']['segment']);
        $this->table(['metric', 'count'], collect($report['shadow_audience'])->except(['segment', 'exclusions_by_reason'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        $exclusions = [];
        foreach ((array) $report['shadow_audience']['exclusions_by_reason'] as $reason => $count) {
            $exclusions[] = [$reason, $count];
        }
        $this->table(['exclusion reason', 'count'], $exclusions);
        $this->info('Open conflicts: '.json_encode($report['conflicts']));

        return self::SUCCESS;
    }
}
