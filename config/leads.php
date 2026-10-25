<?php

return [

    // Public subscribe requests above this size are refused (bytes).
    'max_payload_bytes' => 4096,

    // Per IP and form, and per form overall (requests per minute).
    'rate_limits' => ['per_ip' => 10, 'per_form' => 600],

    // Domains refused as lead addresses (disposable mail); empty = allow all.
    'disposable_domains' => array_values(array_filter(explode(',', (string) env('LEAD_DISPOSABLE_DOMAINS', '')))),

    // Store-side double opt-in confirmation window (not İYS).
    'double_opt_in_hours' => 72,

    // Query parameters never stored from a page URL (only host and path are kept anyway).
    'utm_max_length' => 100,

    // Bot verification driver: null (honeypot only) | future: turnstile, hcaptcha.
    'bot_verifier' => env('LEAD_BOT_VERIFIER', 'null'),

];
