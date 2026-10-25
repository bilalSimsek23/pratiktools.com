<?php

namespace App\Leads\Contracts;

/**
 * Bot protection for public forms (e.g. Turnstile, hCaptcha). The token
 * comes from the browser; verification happens server-side.
 */
interface BotVerifier
{
    public function passes(?string $token, ?string $ip): bool;
}
