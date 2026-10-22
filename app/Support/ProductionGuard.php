<?php

namespace App\Support;

use RuntimeException;

/**
 * Refuses to boot staging/production with unsafe settings.
 */
final class ProductionGuard
{
    /**
     * Build-time commands that never serve traffic (composer install runs
     * package:discover before any environment file exists).
     */
    private const BUILD_COMMANDS = ['package:discover', 'vendor:publish', 'list', 'help', 'about', 'env'];

    public static function assertSafe(): void
    {
        if (! app()->environment(['production', 'staging']) || self::isBuildCommand()) {
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

    private static function isBuildCommand(): bool
    {
        return app()->runningInConsole() && in_array($_SERVER['argv'][1] ?? null, self::BUILD_COMMANDS, true);
    }
}
