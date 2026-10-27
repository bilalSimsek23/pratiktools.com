<?php

namespace App\Consent\Via;

/**
 * İYS ViA (consent frame and ViA Ret) for one tenant.
 *
 * ViA writes consent to İYS itself; PratikTools only learns the outcome
 * from a ViA event. No official event format, signature scheme or frame
 * configuration is available yet, so the only implementation reports
 * itself as not configured and verifies nothing. A real implementation
 * must:
 *  - bind the frame to the tenant from stored configuration (never from a
 *    request parameter or header);
 *  - verify the event authenticity as documented by İYS before trusting it;
 *  - map it to a ViaConsentEvent.
 */
interface ViaConsentProvider
{
    public function status(): ViaStatus;

    /**
     * The tenant's frame, or null when none is configured.
     */
    public function frame(): ?ViaFrameConfig;

    /**
     * Verifies an inbound ViA event and returns it, or throws ViaError.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function verifyEvent(array $payload, array $headers): ViaConsentEvent;
}
