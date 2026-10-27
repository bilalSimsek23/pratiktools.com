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

    // F2-R: ViA consent acquisition + İYS daily change reports. The tenant's
    // consent_strategy (direct_api | via_reconciliation) is chosen by an
    // operator (consent:strategy); nothing here switches it.
    'reconciliation' => [
        // Hours an applied change report (its period end) keeps consent
        // decisions current. No default: the legal freshness window is an
        // operator decision, and without it via_reconciliation stays
        // fail-closed.
        'max_age_hours' => env('IYS_RECONCILIATION_MAX_AGE'),
        // Report format (a key of parsers). No official format is known
        // yet, so none is registered and uploads are refused as
        // FORMAT_NOT_CONFIGURED instead of guessed.
        'format' => env('IYS_RECONCILIATION_FORMAT'),
        'parsers' => [],
        'max_file_bytes' => (int) env('IYS_RECONCILIATION_MAX_FILE_BYTES', 50 * 1024 * 1024),
        'max_line_bytes' => 4096,
        'max_rows' => (int) env('IYS_RECONCILIATION_MAX_ROWS', 2_000_000),
        'chunk' => 500,
    ],

    'via' => [
        // Pending ViA acquisitions older than this are expired (never ONAY).
        'pending_hours' => 72,
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
