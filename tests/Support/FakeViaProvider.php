<?php

namespace Tests\Support;

use App\Consent\Via\ViaConsentEvent;
use App\Consent\Via\ViaConsentProvider;
use App\Consent\Via\ViaError;
use App\Consent\Via\ViaFrameConfig;
use App\Consent\Via\ViaStatus;

/**
 * TEST ONLY: a ViA provider that reports a configured frame. Events are
 * built by the tests directly; nothing is verified or called.
 */
final class FakeViaProvider implements ViaConsentProvider
{
    public const FRAME = 'frame-test-0001';

    public function status(): ViaStatus
    {
        return new ViaStatus(ViaStatus::CONFIGURED, 'test');
    }

    public function frame(): ?ViaFrameConfig
    {
        return new ViaFrameConfig('test', self::FRAME, 'sandbox');
    }

    public function verifyEvent(array $payload, array $headers): ViaConsentEvent
    {
        throw new ViaError('NOT_IN_TESTS');
    }
}
