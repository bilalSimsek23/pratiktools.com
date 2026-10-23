<?php

return [

    'ticimax' => [
        'connect_timeout' => (int) env('TICIMAX_CONNECT_TIMEOUT', 15),
        'read_timeout' => (int) env('TICIMAX_READ_TIMEOUT', 90),
        'max_attempts' => 3,
        'base_backoff_ms' => 500,
        // Request/response tracing; never enabled in production.
        'soap_trace' => (bool) env('TICIMAX_SOAP_TRACE', false),

        // Page sizes verified against the live API (members: 500 works).
        'member_page_size' => 500,
        'product_page_size' => 100,
        'order_page_size' => 100,
        'max_cart_pages' => 500,

        // SiparisKaynagi values (upper case) that mark marketplace orders,
        // in addition to the IsMarketplace flag.
        'marketplace_sources' => ['TRENDYOL', 'HEPSIBURADA', 'BOYNER', 'N11', 'LCWAIKIKI', 'PAZARAMA', 'AMAZON', 'CICEKSEPETI', 'MODANISA', 'PTTAVM', 'MORHIPO', 'FLO', 'KOTON', 'TEMU', 'IDEFIX'],
    ],

    // Masked/relay e-mail domains used by marketplaces. Never used to create
    // a customer identity, whatever the order channel says.
    'marketplace_email_domains' => [
        'trendyolmail.com', 'hepsifatura.com', 'hepsiburada.com', 'boynerfatura.com', 'n11.com',
        'ciceksepeti.com', 'amazon.com.tr', 'marketplace.amazon.com.tr', 'pazarama.com', 'falsemail.com',
    ],

    'sync' => [
        // A sync job works for at most this long, then re-queues itself so
        // other tenants' jobs get a turn on the queue.
        'slice_seconds' => (int) env('COMMERCE_SYNC_SLICE_SECONDS', 45),
        // Delta windows start this much before the previous window end.
        'overlap_minutes' => ['customers' => 30, 'products' => 30, 'orders' => 60, 'categories' => 0, 'carts' => 0],
        // Windows end this far in the past so late writes are not missed.
        'safety_lag_seconds' => 120,
        // Carts are filtered by creation date only; each delta re-reads this
        // look-back to see carts that changed after creation.
        'cart_lookback_hours' => 48,
        // First full order sync reads this far back.
        'order_backfill_days' => (int) env('COMMERCE_ORDER_BACKFILL_DAYS', 365),
        // Full cart sync reads this far back.
        'cart_backfill_days' => 14,
    ],

    // Disabled by default. Enable per environment once a tenant is verified.
    'schedule' => [
        'enabled' => (bool) env('COMMERCE_SYNC_SCHEDULE_ENABLED', false),
        'deltas' => [
            'customers' => '*/30 * * * *',
            'orders' => '*/15 * * * *',
            'carts' => '*/15 * * * *',
            'products' => '0 * * * *',
            'categories' => '0 4 * * *',
        ],
        // Nightly reconciliation: products full (cheap, ~50 calls for 5k
        // products), orders re-read for the last 7 days, subscribers full.
        'reconciliation' => '30 3 * * *',
        'reconciliation_order_days' => 7,
    ],

];
