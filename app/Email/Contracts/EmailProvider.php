<?php

namespace App\Email\Contracts;

use App\Email\Data\ConnectionResult;
use App\Email\Data\ProviderSendResult;
use App\Email\EmailCapabilities;
use App\Email\EmailSendAuthorization;
use App\Email\OutboundEmail;

/**
 * A delivery provider (Amazon SES, later Brevo, SendGrid, Mailgun …).
 * Providers only deliver: they never choose recipients, decide consent or
 * keep the source of truth for suppressions.
 */
interface EmailProvider
{
    public function name(): string;

    public function capabilities(): EmailCapabilities;

    /**
     * Read-only account check (status, sandbox, quota). Never sends.
     */
    public function testConnection(): ConnectionResult;

    /**
     * Deliver one authorized message. Throws ProviderError on failure.
     */
    public function send(EmailSendAuthorization $authorization, OutboundEmail $email): ProviderSendResult;

    /**
     * Deliver several authorized messages, optimized per provider (one call
     * per message where the provider has no multi-message raw send).
     *
     * @param  list<array{0: EmailSendAuthorization, 1: OutboundEmail}>  $messages
     * @return list<ProviderSendResult|\Throwable>
     */
    public function sendBatch(array $messages): array;

    /**
     * Provider health from stored state; never a network call.
     *
     * @return array<string, mixed>
     */
    public function health(): array;
}
