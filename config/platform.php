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
        'kek_driver' => env('SECRET_VAULT_KEK_DRIVER', 'local'),

        'allow_local_kek_in_production' => (bool) env('SECRET_VAULT_ALLOW_LOCAL_KEK_IN_PRODUCTION', false),

        'local' => [
            'key_id' => env('SECRET_VAULT_LOCAL_KEK_ID', 'local-v1'),
            'key' => env('SECRET_VAULT_LOCAL_KEK'),
            // Comma separated "key_id:base64key" pairs kept for unwrapping
            // DEKs that were wrapped before a KEK rotation.
            'previous' => env('SECRET_VAULT_LOCAL_PREVIOUS_KEKS', ''),
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
        ],
    ],

];
