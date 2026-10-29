<?php

namespace App\Consent\Via;

/**
 * The tenant's ViA form as events are matched against it (no secret).
 * frameId is the İYS ViA Form ID.
 */
final readonly class ViaFrameConfig
{
    public function __construct(
        public string $integrationId,
        public string $frameId,
        public string $environment,
        public string $formType = 'OTP',
        public string $approvalType = 'ETK',
    ) {}
}
