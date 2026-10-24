<?php

namespace App\Email;

/**
 * Effective e-mail sending mode: the stricter of the platform switch
 * (EMAIL_SENDING_MODE) and the tenant's sending_mode.
 */
enum EmailSendingMode: string
{
    case Off = 'off';
    case TestOnly = 'test_only';
    case Live = 'live';

    public static function platform(): self
    {
        return self::tryFrom((string) config('email.sending_mode')) ?? self::Off;
    }

    public static function forTenant(?string $tenantSendingMode): self
    {
        $tenant = match ($tenantSendingMode) {
            'live' => self::Live,
            'internal', 'pilot' => self::TestOnly,
            default => self::Off,
        };

        return self::stricter(self::platform(), $tenant);
    }

    private static function stricter(self $a, self $b): self
    {
        $rank = [self::Off->value => 0, self::TestOnly->value => 1, self::Live->value => 2];

        return $rank[$a->value] <= $rank[$b->value] ? $a : $b;
    }
}
