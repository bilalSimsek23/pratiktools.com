<?php

namespace App\Consent\Via;

/**
 * Whether ViA can be used for production consent acquisition, and why not.
 * level: PASS (usable), WAIT (an external step is pending: İYS approval or
 * the official technical specification), BLOCKED (configuration problem).
 */
final readonly class ViaStatus
{
    public const NOT_CONFIGURED = 'not_configured';

    public const FORM_ID_MISSING = 'form_id_missing';

    public const FORM_DRAFT = 'form_draft';

    public const FORM_PENDING_APPROVAL = 'form_pending_approval';

    public const FORM_REJECTED = 'form_rejected';

    public const FORM_DISABLED = 'form_disabled';

    public const UNSUPPORTED_FORM = 'unsupported_form';

    // Approved form, but no official OTP transport is implemented yet.
    public const TRANSPORT_NOT_CONFIGURED = 'transport_not_configured';

    public const FAKE_TRANSPORT_IN_PRODUCTION = 'fake_transport_in_production';

    public const CONFIGURED = 'configured';

    private const LEVELS = [
        self::CONFIGURED => 'PASS',
        self::FORM_PENDING_APPROVAL => 'WAIT',
        self::FORM_DRAFT => 'WAIT',
        self::TRANSPORT_NOT_CONFIGURED => 'WAIT',
    ];

    public function __construct(public string $state, public string $detail) {}

    public function configured(): bool
    {
        return $this->state === self::CONFIGURED;
    }

    public function level(): string
    {
        return self::LEVELS[$this->state] ?? 'BLOCKED';
    }

    /**
     * Readiness reason code (ReadinessCatalog), null when usable.
     */
    public function blocker(): ?string
    {
        return $this->configured() ? null : 'VIA_'.strtoupper($this->state);
    }
}
