<?php

namespace App\Consent\Via;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The ViA provider until İYS provides the frame configuration and the
 * official event format: reads the stored frame (integration provider
 * "iys_via", capability "consent_acquisition", setting frame_id) for
 * display, never reports ViA as usable and refuses every event.
 */
final class UnconfiguredViaProvider implements ViaConsentProvider
{
    public function __construct(private readonly TenantContext $context) {}

    public function status(): ViaStatus
    {
        return $this->frame() === null
            ? new ViaStatus(ViaStatus::NOT_CONFIGURED, 'No ViA account or frame is configured for this tenant.')
            : new ViaStatus(ViaStatus::AWAITING_OFFICIAL_SPEC, 'A ViA frame is stored, but the official ViA event format is not available; no event can be verified.');
    }

    public function frame(): ?ViaFrameConfig
    {
        $row = DB::table('tenant_integrations')->where('tenant_id', $this->context->requireTenant())
            ->where('provider', 'iys_via')->where('capability', 'consent_acquisition')->first(['id', 'settings']);
        $settings = $row === null ? [] : (array) json_decode((string) $row->settings, true);

        return is_string($settings['frame_id'] ?? null) && $settings['frame_id'] !== ''
            ? new ViaFrameConfig((string) $row?->id, $settings['frame_id'], (string) ($settings['environment'] ?? 'sandbox'))
            : null;
    }

    public function verifyEvent(array $payload, array $headers): ViaConsentEvent
    {
        throw new ViaError('VIA_EVENT_FORMAT_UNVERIFIED', 'ViA events cannot be verified until the official event format is available.');
    }
}
