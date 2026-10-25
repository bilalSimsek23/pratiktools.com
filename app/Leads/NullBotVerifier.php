<?php

namespace App\Leads;

use App\Leads\Contracts\BotVerifier;

/**
 * Default: no external challenge (the honeypot and rate limits still apply).
 */
final class NullBotVerifier implements BotVerifier
{
    public function passes(?string $token, ?string $ip): bool
    {
        return true;
    }
}
