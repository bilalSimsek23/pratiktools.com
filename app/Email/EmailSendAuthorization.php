<?php

namespace App\Email;

/**
 * Proof that EmailSendGuard approved this message for this tenant,
 * integration, recipient and mode. The constructor is private; only the
 * guard creates one (scope-bound closure). Providers refuse to send
 * without it and re-check that it matches the message.
 */
final class EmailSendAuthorization
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $integrationId,
        public readonly string $messageId,
        public readonly string $recipient,
        public readonly EmailSendingMode $mode,
        public readonly EmailMessageType $type,
    ) {}

    public function covers(OutboundEmail $email, string $integrationId): bool
    {
        return $this->mode !== EmailSendingMode::Off
            && $this->integrationId === $integrationId
            && $this->messageId === $email->messageId
            && $this->recipient === $email->to
            && $this->type === $email->type;
    }
}
