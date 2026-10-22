<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationVerifier;
use App\Webhooks\Contracts\WebhookHandler;
use App\Webhooks\Contracts\WebhookSignatureVerifier;

/**
 * Provider catalogue and per-provider extension points. Connector phases
 * register verifiers and webhook handlers here; F0 registers none.
 */
final class ProviderRegistry
{
    /**
     * Providers and the capabilities each can serve.
     */
    public const PROVIDERS = [
        'ticimax' => ['commerce', 'coupon'],
        'iys' => ['consent'],
        'klaviyo' => ['email'],
        'onesignal' => ['push'],
    ];

    /** @var array<string, IntegrationVerifier> */
    private array $verifiers = [];

    /** @var array<string, WebhookSignatureVerifier> */
    private array $signatureVerifiers = [];

    /** @var array<string, WebhookHandler> */
    private array $webhookHandlers = [];

    public function isKnown(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    public function supports(string $provider, string $capability): bool
    {
        return in_array($capability, self::PROVIDERS[$provider] ?? [], true);
    }

    public function registerVerifier(string $provider, IntegrationVerifier $verifier): void
    {
        $this->verifiers[$provider] = $verifier;
    }

    public function verifier(string $provider): IntegrationVerifier
    {
        return $this->verifiers[$provider] ?? new NullIntegrationVerifier;
    }

    public function registerSignatureVerifier(string $provider, WebhookSignatureVerifier $verifier): void
    {
        $this->signatureVerifiers[$provider] = $verifier;
    }

    public function signatureVerifier(string $provider): ?WebhookSignatureVerifier
    {
        return $this->signatureVerifiers[$provider] ?? null;
    }

    public function registerWebhookHandler(string $provider, WebhookHandler $handler): void
    {
        $this->webhookHandlers[$provider] = $handler;
    }

    public function webhookHandler(string $provider): ?WebhookHandler
    {
        return $this->webhookHandlers[$provider] ?? null;
    }
}
