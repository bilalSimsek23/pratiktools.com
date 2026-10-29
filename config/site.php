<?php

return [

    // Changeable site settings live in the database (site_settings, panel:
    // Web Sitesi › Site Ayarları). The values below are only first-boot
    // fallbacks used while a setting has never been saved.

    // Owner tenant slug (normally recorded by `site:setup --tenant=`).
    'owner_tenant' => env('SITE_OWNER_TENANT'),

    // Public id (lf_...) of the website lead form (normally recorded by site:setup).
    'lead_form' => env('SITE_LEAD_FORM'),

    'name' => 'PratikTools',

    // HTML pages: browser/CDN cache seconds (fallback; setting "delivery").
    'cache_seconds' => (int) env('SITE_CACHE_SECONDS', 300),

    // Image disk (fallback; setting "media"). Production accepts only a
    // persistent (object storage) disk; its credentials stay in the
    // environment as secrets.
    // Unset: Laravel Cloud's attached "site-media" disk when present, else local.
    'media_disk' => env('SITE_MEDIA_DISK'),
    'cloud_media_disk' => 'site-media',

    // Hard security limits (code, not settings).
    'media_max_bytes' => 5 * 1024 * 1024,
    'media_max_pixels' => 40_000_000,
    'media_widths' => [1600, 800],

    // Inquiry form.
    'inquiry' => [
        'max_payload_bytes' => 16384,
        'min_seconds' => 3,
        'max_age_hours' => 24,
        'per_ip_per_minute' => 5,
        'per_ip_per_hour' => 20,
    ],

    // Crawler policy (robots.txt). OAI-SearchBot (ChatGPT search) and
    // search engines may crawl public pages; GPTBot (model training) is a
    // separate site setting, default disallowed.
    'disallow_paths' => ['/app', '/api', '/u/', '/health', '/healthz', '/webhooks', '/public/v1', '/iletisim/talep', '/t', '/iletisim/tesekkurler'],

    // Known AI assistant / answer engine referrers for lead attribution.
    'ai_referrers' => ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'www.perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com', 'claude.ai', 'you.com'],

    'search_referrers' => ['google.', 'bing.com', 'yandex.', 'duckduckgo.com', 'yahoo.com', 'ecosia.org'],

    'social_referrers' => ['facebook.com', 'instagram.com', 'l.facebook.com', 'lm.facebook.com', 't.co', 'linkedin.com', 'lnkd.in', 'x.com', 'twitter.com', 'youtube.com'],

];
