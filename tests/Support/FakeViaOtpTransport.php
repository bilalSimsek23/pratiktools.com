<?php

namespace Tests\Support;

use App\Consent\Via\ViaConfig;
use App\Consent\Via\ViaOtpStartRequest;
use App\Consent\Via\ViaOtpStartResult;
use App\Consent\Via\ViaOtpTransport;
use App\Consent\Via\ViaOtpVerifyRequest;
use App\Consent\Via\ViaOtpVerifyResult;
use Carbon\CarbonImmutable;

/**
 * TEST ONLY: an in-memory ViA OTP transport. It does not model the real
 * ViA API (unknown); it only lets tests drive the guarded provider. It
 * reports itself as fake, so production refuses it.
 */
final class FakeViaOtpTransport implements ViaOtpTransport
{
    public int $starts = 0;

    public int $verifies = 0;

    public ?ViaOtpVerifyResult $next = null;

    public function configured(): bool
    {
        return true;
    }

    public function isFake(): bool
    {
        return true;
    }

    public function start(ViaConfig $config, ViaOtpStartRequest $request): ViaOtpStartResult
    {
        $this->starts++;

        return new ViaOtpStartResult(true, 'fake-otp-'.$request->reference);
    }

    public function verify(ViaConfig $config, ViaOtpVerifyRequest $request): ViaOtpVerifyResult
    {
        $this->verifies++;

        return $this->next ?? ($request->code->reveal() === '123456'
            ? new ViaOtpVerifyResult(true, $request->providerReference, CarbonImmutable::now()->subSecond())
            : new ViaOtpVerifyResult(false, null, failureCode: 'wrong_code'));
    }
}
