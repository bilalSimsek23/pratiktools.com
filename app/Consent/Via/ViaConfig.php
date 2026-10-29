<?php

namespace App\Consent\Via;

/**
 * The tenant's ViA configuration (integration provider "via", capability
 * "consent_acquisition"; settings, no secret). Values come only from
 * stored configuration, never from code.
 */
final readonly class ViaConfig
{
    // The only form and approval types seen in the İYS portal so far.
    public const FORM_TYPES = ['OTP'];

    public const APPROVAL_TYPES = ['ETK'];

    public function __construct(
        public string $integrationId,
        public ?string $iysNumber,
        public ?string $brand,
        public string $packageStatus,
        public string $formType,
        public string $approvalType,
        public ?string $formId,
        public ViaFormStatus $formStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(string $integrationId, array $settings): self
    {
        $str = fn (string $k) => is_scalar($settings[$k] ?? null) && trim((string) $settings[$k]) !== '' ? trim((string) $settings[$k]) : null;

        return new self($integrationId, $str('iys_number'), $str('brand'), $str('package_status') ?? 'unknown', $str('form_type') ?? 'unknown',
            $str('approval_type') ?? 'unknown', $str('form_id'), ViaFormStatus::tryFrom((string) $str('form_status')) ?? ViaFormStatus::Draft);
    }

    public function maskedFormId(): ?string
    {
        if ($this->formId === null) {
            return null;
        }
        $len = mb_strlen($this->formId);

        return $len <= 4 ? str_repeat('•', $len) : mb_substr($this->formId, 0, 2).str_repeat('•', min(8, $len - 4)).mb_substr($this->formId, -2);
    }
}
