<?php

return [

    'iys' => [
        // From the official İYS API document (3.2.2).
        'sandbox_url' => 'https://api.sandbox.iys.org.tr',
        'production_url' => 'https://api.iys.org.tr',
        // Production only with registered static egress IPs.
        'allow_production' => (bool) env('IYS_ALLOW_PRODUCTION', false),
        'timeout' => (int) env('IYS_TIMEOUT', 30),
        'max_attempts' => 4,
        'base_backoff_ms' => 500,
        // Single status calls per hour reserved for resolving "not granted"
        // addresses (limit: 1,000 per hour per IP, shared by all tenants).
        'resolve_budget_per_run' => (int) env('IYS_RESOLVE_BUDGET', 800),
        // Recipient types queried in bulk for e-mail.
        'recipient_types' => ['INDIVIDUAL', 'MERCHANT'],
        // A changes cursor older than this cannot be continued (İYS keeps
        // 7 days minus 1 hour); a full status sync is needed instead.
        'changes_window_hours' => 167,
    ],

    'ticimax' => [
        // First month read by the initial store permission history import.
        'history_start' => env('CONSENT_TICIMAX_HISTORY_START', '2019-01-01'),
    ],

    'eligibility' => [
        // İYS observations older than this block sending (IYS_STALE).
        'iys_max_age_hours' => (int) env('CONSENT_IYS_MAX_AGE_HOURS', 48),
        // An explicit opt-out recorded at the store after the İYS consent
        // date blocks sending even when İYS says ONAY.
        'store_opt_out_blocks' => true,
    ],

    // Shadow mode: PratikTools never writes consent anywhere.
    'shadow_mode' => true,

    'schedule' => [
        'enabled' => (bool) env('CONSENT_SYNC_SCHEDULE_ENABLED', false),
        'changes' => '15 * * * *',
        'status' => '0 2 * * *',
        'ticimax_history' => '40 * * * *',
    ],

];
