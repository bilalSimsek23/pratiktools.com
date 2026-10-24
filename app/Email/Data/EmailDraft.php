<?php

namespace App\Email\Data;

use App\Email\EmailMessageType;
use Carbon\CarbonImmutable;

/**
 * Everything needed to queue one message for one recipient. The sender
 * (from, name, reply-to) always comes from the sender identity.
 */
final readonly class EmailDraft
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $integrationId,
        public string $senderIdentityId,
        public EmailMessageType $type,
        public string $to,
        public string $subject,
        public string $text,
        public ?string $html = null,
        public ?string $idempotencyKey = null,
        public ?string $customerId = null,
        public ?string $campaignId = null,
        public ?string $automationRunId = null,
        public ?string $snapshotId = null,
        public ?string $templateVersionId = null,
        public array $headers = [],
        public array $tags = [],
        public array $metadata = [],
        public ?CarbonImmutable $sendAt = null,
    ) {}
}
