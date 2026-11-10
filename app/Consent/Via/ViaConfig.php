<?php

namespace App\Consent\Via;

/**
 * The tenant's ViA configuration (integration provider "via", capability
 * "consent_acquisition"; settings, no secret). Values come only from
 * stored configuration, never from code. frameOrigins: the https origins
 * where the tenant embeds the official ViA Frame (normalized).
 *
 * Three separate İYS values, never mixed up:
 * - formId: the ViA form in the İYS portal (the form whose status is
 *   recorded; the server-side ViA path matches against it).
 * - widgetUuid: the ViA Frame Widget UUID İYS issues on activation; the
 *   loader URL and the container id. Kept exactly as İYS gave it (no case
 *   change), because the loader looks the container up by that exact id.
 * - the DNS TXT token ("via-frame=…") that proves the domain to İYS: never
 *   stored, PratikTools has no use for it.
 *
 * domainActivation: a Super Admin's audited confirmation that İYS
 * activated the Frame for one origin, bound to the Widget UUID and the
 * origin list it was given for (stale as soon as either changes).
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
        /** @var list<string> */
        public array $frameOrigins = [],
        public ?string $widgetUuid = null,
        /** @var array{origin: string, widget_uuid: string, frame_origins: list<string>, confirmed_at: string, confirmed_by: ?string, note: string}|null */
        public ?array $domainActivation = null,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(string $integrationId, array $settings): self
    {
        $str = fn (string $k) => is_scalar($settings[$k] ?? null) && trim((string) $settings[$k]) !== '' ? trim((string) $settings[$k]) : null;

        return new self($integrationId, $str('iys_number'), $str('brand'), $str('package_status') ?? 'unknown', $str('form_type') ?? 'unknown',
            $str('approval_type') ?? 'unknown', $str('form_id'), ViaFormStatus::tryFrom((string) $str('form_status')) ?? ViaFormStatus::Draft,
            array_values(array_filter((array) ($settings['frame_origins'] ?? []), 'is_string')), $str('widget_uuid'),
            self::activation($settings['domain_activation'] ?? null));
    }

    /**
     * The domain activation as stored, or null when it is missing or
     * malformed (a broken record never counts as confirmed).
     *
     * @return array{origin: string, widget_uuid: string, frame_origins: list<string>, confirmed_at: string, confirmed_by: ?string, note: string}|null
     */
    private static function activation(mixed $a): ?array
    {
        if (! is_array($a) || ! is_string($a['origin'] ?? null) || ! is_string($a['widget_uuid'] ?? null) || ! is_array($a['frame_origins'] ?? null)
            || ! is_string($a['confirmed_at'] ?? null)) {
            return null;
        }

        return ['origin' => $a['origin'], 'widget_uuid' => $a['widget_uuid'], 'frame_origins' => array_values(array_filter($a['frame_origins'], 'is_string')),
            'confirmed_at' => $a['confirmed_at'], 'confirmed_by' => is_string($a['confirmed_by'] ?? null) ? $a['confirmed_by'] : null, 'note' => (string) ($a['note'] ?? '')];
    }

    /**
     * The confirmed İYS-activated origin, only while the confirmation still
     * matches the stored Widget UUID (exact, case-sensitive) and origins.
     */
    public function activatedOrigin(): ?string
    {
        $a = $this->domainActivation;

        return $a !== null && $this->widgetUuid !== null && $a['widget_uuid'] === $this->widgetUuid && $a['frame_origins'] === $this->frameOrigins
            && in_array($a['origin'], $this->frameOrigins, true) ? $a['origin'] : null;
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
