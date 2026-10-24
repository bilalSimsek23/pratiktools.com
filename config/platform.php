<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    |
    | The Super Admin panel lives on its own host and never shares routes with
    | the tenant API. Tenant API and webhook routes refuse the admin host.
    |
    */

    'admin_domain' => env('ADMIN_DOMAIN', 'admin.saas.localhost'),

    /*
    |--------------------------------------------------------------------------
    | Outbound network
    |--------------------------------------------------------------------------
    |
    | Static egress IPs that providers with IP allowlists (IYS, OneSignal)
    | must whitelist. Informational in F0; set per environment.
    |
    */

    /*
    | Proxies trusted for X-Forwarded-For/Proto/Port. Empty: the immediate
    | peer on Laravel Cloud and in production, none elsewhere. A comma list
    | of CIDRs adds proxies; "none" disables. Never "trust everyone".
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'egress_static_ips' => array_values(array_filter(explode(',', (string) env('EGRESS_STATIC_IPS', '')))),

    /*
    |--------------------------------------------------------------------------
    | Secret vault (envelope encryption)
    |--------------------------------------------------------------------------
    |
    | Every tenant has its own data encryption key (DEK). DEKs are wrapped by a
    | key encryption key (KEK) that lives in a KMS. The "local" driver keeps
    | the KEK in the environment and exists for development and tests only.
    | APP_KEY is never used for tenant secrets.
    |
    */

    'vault' => [
        // cloud_secret (production default), aws_kms (optional) or local
        // (development and tests only; refused in production).
        'kek_driver' => env('SECRET_VAULT_KEK_DRIVER', 'local'),

        // Staging only: allows the local KEK there. Ignored in production.
        'allow_local_kek_in_staging' => (bool) env('SECRET_VAULT_ALLOW_LOCAL_KEK_IN_STAGING', false),

        'local' => [
            'key_id' => env('SECRET_VAULT_LOCAL_KEK_ID', 'local-v1'),
            'key' => env('SECRET_VAULT_LOCAL_KEK'),
            // Comma separated "key_id:base64key" pairs kept for unwrapping
            // DEKs that were wrapped before a KEK rotation.
            'previous' => env('SECRET_VAULT_LOCAL_PREVIOUS_KEKS', ''),
        ],

        // Platform KEK from a Laravel Cloud Secret: base64 of 32 random bytes
        // (php artisan vault:generate-kek). PREVIOUS only during a rotation:
        // comma-separated base64 keys, at most 3.
        'cloud_secret' => [
            'key' => env('SECRET_VAULT_KEK'),
            'previous' => env('SECRET_VAULT_KEK_PREVIOUS', ''),
        ],

        // Optional: symmetric AWS KMS key (full key ARN, not an alias). Credentials
        // belong to an IAM user allowed only kms:Encrypt and kms:Decrypt on
        // this key.
        'aws_kms' => [
            'key_id' => env('SECRET_VAULT_AWS_KMS_KEY_ID'),
            'region' => env('SECRET_VAULT_AWS_REGION', 'eu-central-1'),
            'access_key_id' => env('SECRET_VAULT_AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('SECRET_VAULT_AWS_SECRET_ACCESS_KEY'),
        ],

        // HMAC key for secret fingerprints, so a fingerprint can be compared
        // without being usable to brute-force the secret offline.
        'fingerprint_key' => env('SECRET_VAULT_FINGERPRINT_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    */

    'audit' => [
        // HMAC key for ip_hash and user_agent_hash.
        'hash_key' => env('AUDIT_HASH_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    */

    'queues' => ['system', 'webhooks', 'sync', 'automation', 'send'],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    */

    'health' => [
        // The scheduled heartbeat runs every minute.
        'heartbeat_max_age_seconds' => (int) env('HEALTH_HEARTBEAT_MAX_AGE', 300),
        // Set true once the scheduler and Horizon are enabled in production.
        'require_heartbeat' => (bool) env('HEALTH_REQUIRE_HEARTBEAT', false),
        'kms_cache_seconds' => (int) env('HEALTH_KMS_CACHE_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook inbox
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        'max_payload_bytes' => (int) env('WEBHOOK_MAX_PAYLOAD_BYTES', 262144),

        // Only these request headers are stored. Signature headers are never
        // stored, only whether they were present.
        'stored_headers' => ['content-type', 'content-length', 'user-agent'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider rate limits (token buckets)
    |--------------------------------------------------------------------------
    |
    | Filled in by connector phases. Each provider may declare a per-tenant
    | bucket and an optional platform-wide bucket (for example IYS, whose
    | limits apply per egress IP and are shared by every tenant).
    |
    | 'iys' => [
    |     'tenant' => ['capacity' => 5, 'refill_per_second' => 5],
    |     'global' => ['capacity' => 10, 'refill_per_second' => 10],
    | ],
    |
    */

    'rate_limits' => [
        'providers' => [
            // Conservative: Ticimax publishes no limits; the store's own admin
            // and storefront share the same backend.
            'ticimax' => [
                'tenant' => ['capacity' => 4, 'refill_per_second' => 2],
            ],
            // İYS limits apply per caller IP, i.e. platform-wide. The API
            // answers H124 ("per-second limit") without publishing it, so
            // the general bucket is conservative.
            'iys' => [
                'tenant' => ['capacity' => 3, 'refill_per_second' => 2],
                'global' => ['capacity' => 5, 'refill_per_second' => 4],
            ],
            // Token endpoint: 10 requests per hour per IP.
            'iys_token' => [
                'global' => ['capacity' => 8, 'refill_per_second' => 8 / 3600],
            ],
            // OneSignal view endpoints (app, users, CSV export): 1 request/s
            // per app; one app per tenant integration.
            'onesignal_view' => [
                'tenant' => ['capacity' => 1, 'refill_per_second' => 1],
            ],
            // Create message: 150/s/app on free plans. Test pushes are tiny.
            'onesignal_send' => [
                'tenant' => ['capacity' => 5, 'refill_per_second' => 2],
            ],
            // SES API v2 account/identity reads (not sending): low and
            // fixed. Sends use a dynamic bucket from the account's
            // MaxSendRate (tenant:{id}:amazon_ses).
            'amazon_ses_read' => [
                'tenant' => ['capacity' => 2, 'refill_per_second' => 1],
            ],
            // Single status: 1,000 requests per hour per IP (90 % used).
            'iys_status' => [
                'global' => ['capacity' => 30, 'refill_per_second' => 900 / 3600],
            ],
        ],
    ],

];
