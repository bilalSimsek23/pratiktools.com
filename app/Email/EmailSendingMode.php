<?php

namespace App\Email;

/**
 * Effective e-mail sending mode: the strictest of the environment upper
 * bound (EMAIL_SENDING_MODE), the platform switch managed in Super Admin
 * (platform_settings) and the tenant's sending_mode.
 */
enum EmailSendingMode: string
{
    case Off = 'off';
    case TestOnly = 'test_only';
    case Live = 'live';

    /**
     * The platform mode every tenant is bounded by: the stricter of the
     * environment upper bound and the Super Admin switch. Off whenever the
     * switch cannot be read (fail-closed).
     */
    public static function platform(): self
    {
        return app(PlatformEmailMode::class)->effective();
    }

    /**
     * EMAIL_SENDING_MODE: the highest mode the platform switch may reach in
     * this environment. Anything unknown counts as off.
     */
    public static function ceiling(): self
    {
        return self::tryFrom((string) config('email.sending_mode')) ?? self::Off;
    }

    public static function forTenant(?string $tenantSendingMode): self
    {
        return self::stricter(self::platform(), self::fromTenant($tenantSendingMode));
    }

    public static function fromTenant(?string $tenantSendingMode): self
    {
        return match ($tenantSendingMode) {
            'live' => self::Live,
            'internal', 'pilot' => self::TestOnly,
            default => self::Off,
        };
    }

    public static function stricter(self $a, self $b): self
    {
        return $a->rank() <= $b->rank() ? $a : $b;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Off => 0,
            self::TestOnly => 1,
            self::Live => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Kapalı',
            self::TestOnly => 'Yalnızca Test',
            self::Live => 'Canlı',
        };
    }
}
