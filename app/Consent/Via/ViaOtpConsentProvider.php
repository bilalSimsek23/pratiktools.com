<?php

namespace App\Consent\Via;

/**
 * ViA OTP consent acquisition for one pending via_consent_requests row:
 * start() asks ViA to send the OTP, verify() checks the code and, only on
 * a verified result, records ONAY (source VIA) with full provenance.
 */
interface ViaOtpConsentProvider
{
    /**
     * @return string the provider reference of the started OTP
     */
    public function start(string $reference): string;

    /**
     * @return 'applied'|'failed'|'duplicate'|'unknown_reference'
     */
    public function verify(string $reference, string $code): string;
}
