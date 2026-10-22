<?php

namespace App\Support;

use RuntimeException;

/**
 * Refuses to boot staging/production with unsafe settings.
 */
final class ProductionGuard
{
    public static function assertSafe(): void
    {
        if (! app()->environment(['production', 'staging'])) {
            return;
        }

        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true';
        }
        if (config('session.http_only') !== true) {
            $problems[] = 'SESSION_HTTP_ONLY must be true';
        }
        if (config('platform.vault.kek_driver') === 'local' && ! config('platform.vault.allow_local_kek_in_production')) {
            $problems[] = 'the local KEK driver is not allowed; configure a KMS driver';
        }
        foreach (['platform.vault.fingerprint_key', 'platform.audit.hash_key', 'app.key'] as $key) {
            if (empty(config($key))) {
                $problems[] = "{$key} is not set";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('Unsafe configuration: '.implode('; ', $problems).'.');
        }
    }
}
