<?php

namespace App\Consent\Via;

/**
 * The network side of ViA OTP. The only production implementation is
 * NotConfiguredViaOtpTransport until İYS provides the official OTP
 * integration instructions; no endpoint, payload or SDK is assumed.
 */
interface ViaOtpTransport
{
    public function configured(): bool;

    /**
     * A test double. Never usable in production.
     */
    public function isFake(): bool;

    public function start(ViaConfig $config, ViaOtpStartRequest $request): ViaOtpStartResult;

    public function verify(ViaConfig $config, ViaOtpVerifyRequest $request): ViaOtpVerifyResult;
}
