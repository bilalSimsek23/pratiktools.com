<?php

namespace App\Consent\Via;

/**
 * NOT_CONFIGURED: no official ViA OTP specification is available, so
 * nothing is sent anywhere.
 */
final class NotConfiguredViaOtpTransport implements ViaOtpTransport
{
    public function configured(): bool
    {
        return false;
    }

    public function isFake(): bool
    {
        return false;
    }

    public function start(ViaConfig $config, ViaOtpStartRequest $request): ViaOtpStartResult
    {
        throw new ViaError('VIA_TRANSPORT_NOT_CONFIGURED');
    }

    public function verify(ViaConfig $config, ViaOtpVerifyRequest $request): ViaOtpVerifyResult
    {
        throw new ViaError('VIA_TRANSPORT_NOT_CONFIGURED');
    }
}
