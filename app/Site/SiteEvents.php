<?php

namespace App\Site;

use Illuminate\Support\Facades\DB;

/**
 * First-party conversion counters: event + public path + day. No cookie,
 * no IP, no visitor id. Analytics providers (GA4, Google Ads, Meta) are
 * fed separately in the browser, only after consent.
 */
final class SiteEvents
{
    public const EVENTS = ['page_view', 'service_view', 'solution_view', 'project_view', 'article_view', 'lead_form_open', 'lead_submit', 'contact_click'];

    public static function path(string $path): ?string
    {
        $path = '/'.trim(strtolower((string) parse_url($path, PHP_URL_PATH)), '/');

        return preg_match('#^/((hizmetler|cozumler|projeler|referanslar|blog)(/[a-z0-9]+(-[a-z0-9]+)*)?|hakkimizda|iletisim)?$#', $path) === 1 ? $path : null;
    }

    public function record(string $event, string $path): bool
    {
        $path = self::path($path);
        if (! in_array($event, self::EVENTS, true) || $path === null) {
            return false;
        }
        DB::statement('INSERT INTO site_events_daily (day, event, path, count) VALUES (current_date, ?, ?, 1)
            ON CONFLICT (day, event, path) DO UPDATE SET count = site_events_daily.count + 1', [$event, $path]);

        return true;
    }
}
