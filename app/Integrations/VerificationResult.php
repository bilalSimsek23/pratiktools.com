<?php

namespace App\Integrations;

final readonly class VerificationResult
{
    public function __construct(
        public string $status,
        public ?string $error = null,
    ) {}

    public static function connected(): self
    {
        return new self('connected');
    }

    public static function failed(string $error): self
    {
        return new self('error', $error);
    }

    public static function notImplemented(): self
    {
        return new self('pending', 'No verifier is available for this provider yet.');
    }
}
