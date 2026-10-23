<?php

namespace App\Consent\Data;

use App\Consent\ConsentChannel;
use App\Consent\EligibilityReason;

final readonly class EligibilityDecision
{
    /**
     * @param  array<string, mixed>  $details  statuses per source, never the address
     */
    public function __construct(
        public ConsentChannel $channel,
        public EligibilityReason $reason,
        public ?string $recipientId = null,
        public array $details = [],
    ) {}

    public function allowed(): bool
    {
        return $this->reason->allows();
    }
}
