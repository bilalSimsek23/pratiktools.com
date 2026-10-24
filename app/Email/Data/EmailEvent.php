<?php

namespace App\Email\Data;

use App\Email\EmailEventType;
use Carbon\CarbonImmutable;

/**
 * A provider event translated to the canonical model. Recipients are the
 * addresses the event applies to (hashed before storage).
 */
final readonly class EmailEvent
{
    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $details  normalized, no message content
     */
    public function __construct(
        public EmailEventType $type,
        public string $provider,
        public ?string $providerMessageId,
        public array $recipients,
        public ?CarbonImmutable $occurredAt,
        public string $eventKey,
        public ?string $bounceClass = null,
        public array $details = [],
    ) {}
}
