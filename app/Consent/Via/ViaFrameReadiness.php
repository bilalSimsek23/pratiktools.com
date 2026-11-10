<?php

namespace App\Consent\Via;

use Illuminate\Support\Str;

/**
 * Whether a tenant's stored ViA configuration is complete enough to embed
 * the official ViA Frame (script + container from viaframe.iys.org.tr; ViA
 * runs the OTP and records the consent at İYS). Computed from stored
 * settings only: nothing contacts ViA or İYS, so the best state is
 * "configuration ready", never "verified by ViA". Separate from
 * IysViaProvider::status(), which gates server-side OTP and stays blocked.
 *
 * READY needs, besides the approved OTP form, the Widget UUID and a Super
 * Admin's confirmation that İYS activated the Frame for the one origin the
 * Frame is embedded on (bound to the current Widget UUID and origins).
 * Browser (Frame) events never count towards readiness.
 */
final readonly class ViaFrameReadiness
{
    public const NOT_CONFIGURED = 'not_configured';

    public const INCOMPLETE = 'incomplete';

    public const READY = 'ready';

    public const PROBLEM = 'problem';

    public const LABELS = [
        self::NOT_CONFIGURED => 'Yapılandırılmadı',
        self::INCOMPLETE => 'Taslak / eksik',
        self::READY => 'Hazır (İYS domain aktivasyonu teyitli)',
        self::PROBLEM => 'Sorunlu',
    ];

    public const LOADER = 'https://viaframe.iys.org.tr/widget/load-widgetloader/';

    /**
     * @param  list<array{key: string, label: string, ok: bool, blocking: bool}>  $checks
     */
    private function __construct(public string $state, public array $checks) {}

    public static function of(?ViaConfig $c): self
    {
        if ($c === null) {
            return new self(self::NOT_CONFIGURED, []);
        }
        $activated = $c->activatedOrigin();
        // blocking: a problem that needs a fix, not just a missing value.
        $checks = [
            ['key' => 'record', 'label' => 'Entegrasyon kaydı (via / consent_acquisition)', 'ok' => true, 'blocking' => false],
            ['key' => 'iys_number', 'label' => 'İYS numarası', 'ok' => $c->iysNumber !== null, 'blocking' => false],
            ['key' => 'brand', 'label' => 'Marka', 'ok' => $c->brand !== null, 'blocking' => false],
            ['key' => 'package', 'label' => 'ViA paketi aktif', 'ok' => $c->packageStatus === 'active', 'blocking' => $c->packageStatus === 'inactive'],
            ['key' => 'form_type', 'label' => 'Form tipi OTP', 'ok' => $c->formType === 'OTP', 'blocking' => ! in_array($c->formType, ['OTP', 'unknown'], true)],
            ['key' => 'form_id', 'label' => 'Portal Form ID geçerli UUID', 'ok' => self::validFormId($c->formId), 'blocking' => $c->formId !== null && ! self::validFormId($c->formId)],
            ['key' => 'form_status', 'label' => 'Form durumu: Onaylandı / Yayında', 'ok' => $c->formStatus === ViaFormStatus::Approved,
                'blocking' => in_array($c->formStatus, [ViaFormStatus::Rejected, ViaFormStatus::Disabled], true)],
            ['key' => 'widget_uuid', 'label' => 'ViA Frame Widget UUID geçerli', 'ok' => self::validWidgetUuid($c->widgetUuid),
                'blocking' => $c->widgetUuid !== null && ! self::validWidgetUuid($c->widgetUuid)],
            ['key' => 'frame_origins', 'label' => 'En az bir izinli Frame origin', 'ok' => $c->frameOrigins !== [], 'blocking' => false],
            ['key' => 'domain_activation', 'label' => 'İYS domain aktivasyonu teyit edildi (Super Admin)', 'ok' => $activated !== null, 'blocking' => false],
            // The loader accepts exactly the host İYS activated; any other
            // origin (www, a subdomain) only shows "Geçersiz alan adı".
            ['key' => 'origin_matches', 'label' => 'Frame origin\'leri yalnız İYS\'nin aktive ettiği alan adı', 'ok' => $activated !== null && $c->frameOrigins === [$activated],
                'blocking' => $activated !== null && $c->frameOrigins !== [$activated]],
        ];
        $state = match (true) {
            collect($checks)->contains('blocking', true) => self::PROBLEM,
            collect($checks)->every('ok', true) => self::READY,
            default => self::INCOMPLETE,
        };

        return new self($state, $checks);
    }

    public function ready(): bool
    {
        return $this->state === self::READY;
    }

    public function label(): string
    {
        return self::LABELS[$this->state];
    }

    public static function validFormId(?string $formId): bool
    {
        return $formId !== null && Str::isUuid($formId);
    }

    public static function validWidgetUuid(?string $uuid): bool
    {
        return $uuid !== null && Str::isUuid($uuid);
    }

    /**
     * The official embed code for a ready configuration, as in İYS's demo:
     * the loader URL and the container id are both the Widget UUID, exactly
     * as stored (the loader finds the container by that exact id). Nothing
     * else (no tenant id, Form ID, secret or token). Null unless ready.
     */
    public function snippet(ViaConfig $c): ?string
    {
        if (! $this->ready() || ! self::validWidgetUuid($c->widgetUuid)) {
            return null;
        }
        $id = e((string) $c->widgetUuid);

        return '<script src="'.self::LOADER.$id.'" defer></script>'."\n".'<div id="'.$id.'"></div>';
    }
}
