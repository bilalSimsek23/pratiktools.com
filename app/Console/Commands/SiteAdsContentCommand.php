<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Site\AdsContent;
use Illuminate\Console\Command;

/**
 * Google Ads readiness content (AdsContent). Dry run by default: prints
 * what would change. --apply writes through the audited content service.
 * Panel edits win; blog posts stay drafts. Safe to run again.
 */
final class SiteAdsContentCommand extends Command
{
    protected $signature = 'site:ads-content {--apply : Write the changes (default: dry run)}';

    protected $description = 'Google Ads readiness content: landing pages, case studies, blog drafts, page corrections';

    public function handle(AdsContent $content): int
    {
        $apply = (bool) $this->option('apply');
        $rows = $content->run($apply, Actor::console($this->getName()));
        $this->table(['content', 'field', 'action'], array_map(fn ($r) => [$r['item'], $r['field'], $r['action']], $rows));
        $kept = count(array_filter($rows, fn ($r) => $r['action'] === 'kept'));
        $missing = count(array_filter($rows, fn ($r) => $r['action'] === 'missing'));
        $this->line(($apply ? 'Applied.' : 'Dry run — nothing written; run with --apply to write.')
            .($kept > 0 ? " {$kept} field(s) kept because they were edited in the panel." : '')
            .($missing > 0 ? " {$missing} item(s) missing." : ''));

        return self::SUCCESS;
    }
}
