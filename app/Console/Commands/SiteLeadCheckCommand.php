<?php

namespace App\Console\Commands;

use App\Site\SiteInquiries;
use App\Site\SiteSettings;
use Illuminate\Console\Command;

/**
 * Readiness of the website lead path before ads: the form can store
 * inquiries, conversion tracking is configured and contact details exist.
 * Prints set / not set only (never an id). Exit 1 when a required check fails.
 */
final class SiteLeadCheckCommand extends Command
{
    protected $signature = 'site:lead-check';

    protected $description = 'Website lead form, conversion tracking and contact details readiness (no ids printed)';

    public function handle(SiteInquiries $inquiries, SiteSettings $settings): int
    {
        $analytics = $settings->get('analytics');
        $org = $settings->get('organization');
        $set = fn (mixed $v) => is_string($v) && $v !== '';
        // [check, ok, required]
        $checks = [
            ['inquiry form accepts leads (owner tenant + lead form)', $inquiries->available(), true],
            ['GA4 id', $set($analytics['ga4_id']), true],
            ['Google Ads id', $set($analytics['google_ads_id']), true],
            ['Google Ads lead conversion label', $set($analytics['google_ads_lead_label']), true],
            ['Google Ads phone conversion label', $set($analytics['google_ads_phone_label']), false],
            ['Google Ads email conversion label', $set($analytics['google_ads_email_label']), false],
            ['organization email', $set($org['email']), false],
            ['organization phone', $set($org['phone']), false],
        ];
        $this->table(['check', 'result'], array_map(fn ($c) => [$c[0], $c[1] ? 'PASS' : ($c[2] ? 'FAIL' : 'not set')], $checks));
        $passed = collect($checks)->every(fn ($c) => $c[1] || ! $c[2]);
        $passed ? $this->info('Lead check PASS.') : $this->error('Lead check FAIL.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
