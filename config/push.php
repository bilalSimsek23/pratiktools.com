<?php

return [

    /*
    | Platform-wide push kill switch: off | test_only | live. The effective
    | mode of a tenant is the stricter of this and the tenant's sending_mode
    | (off → off, internal/pilot → test_only, live → live).
    | Production default: off.
    */
    'sending_mode' => env('PUSH_SENDING_MODE', 'off'),

    'onesignal' => [
        'base_url' => 'https://api.onesignal.com',
        'connect_timeout' => (int) env('ONESIGNAL_CONNECT_TIMEOUT', 5),
        // OneSignal's maximum API response time is about 100 s.
        'timeout' => (int) env('ONESIGNAL_TIMEOUT', 30),
        'max_attempts' => 3,
        'base_backoff_ms' => 2000,
        // CSV export: ~2,000 records/s; the file 404s until ready.
        'export_poll_seconds' => 10,
        // Per slice; a job keeps polling in later slices until ready.
        'export_max_wait_seconds' => 240,
        // Device types stored as push subscriptions; email (11), SMS (14)
        // and others are skipped without storing their identifiers.
        'push_device_types' => [0 => 'ios', 1 => 'android', 2 => 'amazon', 4 => 'web', 5 => 'web', 6 => 'windows', 7 => 'web', 8 => 'web', 9 => 'macos', 12 => 'web', 13 => 'huawei', 17 => 'web'],
        'sync_schedule_enabled' => (bool) env('ONESIGNAL_SYNC_SCHEDULE_ENABLED', false),
        'sync_schedule' => '20 4 * * *',
    ],

    // Test pushes: at most this many recipients per request.
    'max_test_recipients' => 5,

];
