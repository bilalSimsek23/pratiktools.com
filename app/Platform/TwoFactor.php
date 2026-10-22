<?php

namespace App\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) for Super Admins. A code is accepted at most once, so an
 * intercepted code cannot be replayed within its validity window.
 */
final class TwoFactor
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function provisioningUri(PlatformAdmin $admin, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name').' Admin', $admin->email, $secret);
    }

    public function verify(PlatformAdmin $admin, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code) || ! $this->engine->verifyKey($secret, $code, 1)) {
            return false;
        }

        return Cache::add("platform:2fa:used:{$admin->id}:{$code}", true, now()->addSeconds(120));
    }
}
