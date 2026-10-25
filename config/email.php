<?php

return [

    /*
    | Platform-wide e-mail kill switch: off | test_only | live. The effective
    | mode of a tenant is the stricter of this and the tenant's sending_mode
    | (off → off, internal/pilot → test_only, live → live).
    | Production default: off. In test_only nothing leaves the platform
    | except to active test recipients.
    */
    'sending_mode' => env('EMAIL_SENDING_MODE', 'off'),

    // Test e-mails: at most this many recipients per command.
    'max_test_recipients' => 5,

    /*
    | Custom headers a message may carry. Everything else is refused (no
    | arbitrary headers); From, To, Subject, Reply-To are message fields.
    */
    'allowed_headers' => ['List-Unsubscribe', 'List-Unsubscribe-Post', 'X-Entity-Ref-ID'],

    'max_subject_length' => 200,
    'max_html_bytes' => 512 * 1024,
    'max_text_bytes' => 256 * 1024,

    /*
    | Deliverability guard rails for marketing (conservative defaults).
    | Rates are over the last window_days and only count once sample_size
    | messages were sent. AWS reviews an account at 5 % bounces / 0.1 %
    | complaints and may pause it at 10 % / 0.5 %; we stop far earlier.
    */
    'guardrails' => [
        'window_days' => 7,
        'sample_size' => 200,
        'max_bounce_rate' => 0.02,
        'max_complaint_rate' => 0.0008,
        // Marketing messages per recipient.
        'frequency_cap' => ['per_day' => 1, 'per_week' => 3],
        // Keep this share of the provider's 24 h quota for transactional mail.
        'quota_reserve' => 0.1,
    ],

    // Soft bounces (transient) count; this many within the window suppress.
    'soft_bounce' => ['threshold' => 3, 'window_days' => 30],

    /*
    | Unsubscribe links: HMAC-signed, tenant and recipient bound. The key
    | must be set before any marketing send; without it marketing sending
    | fails closed (UNSUBSCRIBE_UNAVAILABLE). Generate with
    | `php -r "echo base64_encode(random_bytes(32));"`.
    */
    'unsubscribe' => [
        'key' => env('EMAIL_UNSUBSCRIBE_KEY'),
        'base_url' => env('EMAIL_UNSUBSCRIBE_BASE_URL', env('APP_URL')),
        'ttl_days' => (int) env('EMAIL_UNSUBSCRIBE_TTL_DAYS', 180),
    ],

    'templates' => [
        // The only variables a template may use.
        'variables' => [
            'customer.first_name', 'customer.last_name', 'customer.full_name',
            'product.name', 'product.url', 'product.image_url', 'product.price',
            'coupon.code', 'coupon.expires_at',
            'store.name', 'store.url',
            'unsubscribe_url', 'current_year', 'campaign.name',
        ],
        // Variables that must be https URLs; rendered URL-safe.
        'url_variables' => ['product.url', 'product.image_url', 'store.url', 'unsubscribe_url'],
    ],

    'ses' => [
        // Region-agnostic: each integration names its own region.
        'default_region' => env('SES_DEFAULT_REGION', 'eu-central-1'),
        'connect_timeout' => (int) env('SES_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('SES_TIMEOUT', 20),
        'max_attempts' => 3,
        'base_backoff_ms' => 1000,
        // Local cap on sends per second, below the account's MaxSendRate.
        'max_send_rate' => (float) env('SES_MAX_SEND_RATE', 10),
        // Share of MaxSendRate we use, leaving headroom for bursts.
        'send_rate_share' => 0.8,
        // Account status (quota, sandbox) older than this counts as unknown.
        'account_max_age_minutes' => 60,
        'sns' => [
            // Unexpected topics are refused; each integration lists its own.
            'max_age_seconds' => 86400,
            'cert_cache_seconds' => 86400,
            // SubscriptionConfirmation is stored, never auto-confirmed;
            // `email:ses confirm-subscription` does it explicitly.
            'auto_confirm' => false,
        ],
    ],

];
