<?php

namespace App\Site;

/**
 * Browser analytics providers from validated site settings. The page only
 * gets the provider ids (JSON config) and the CSP hosts; site.js loads a
 * provider after the visitor accepts analytics cookies and forwards the
 * site events to it. No free-form script ever reaches a page.
 */
final class SiteAnalytics
{
    public function __construct(private readonly SiteSettings $settings) {}

    /**
     * @return array<string, string>
     */
    public function config(): array
    {
        return array_filter($this->settings->get('analytics'), fn ($v) => is_string($v) && $v !== '');
    }

    /**
     * @return array{script: list<string>, connect: list<string>, img: list<string>}
     */
    public function cspHosts(): array
    {
        $c = $this->config();
        $google = isset($c['ga4_id']) || isset($c['google_ads_id']);
        $meta = isset($c['meta_pixel_id']);

        return [
            'script' => array_values(array_filter([$google ? 'https://www.googletagmanager.com' : null, $meta ? 'https://connect.facebook.net' : null])),
            'connect' => array_values(array_filter([$google ? 'https://*.google-analytics.com' : null, $google ? 'https://*.analytics.google.com' : null,
                $google ? 'https://www.googletagmanager.com' : null, $google ? 'https://www.google.com' : null, $meta ? 'https://www.facebook.com' : null])),
            'img' => array_values(array_filter([$google ? 'https://www.googletagmanager.com' : null, $google ? 'https://*.google-analytics.com' : null,
                $google ? 'https://googleads.g.doubleclick.net' : null, $google ? 'https://www.google.com' : null, $meta ? 'https://www.facebook.com' : null])),
        ];
    }
}
