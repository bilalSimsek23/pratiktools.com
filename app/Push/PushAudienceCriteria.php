<?php

namespace App\Push;

/**
 * Provider-independent push audience. Our platform is the source of
 * truth; provider segments are not used.
 */
final readonly class PushAudienceCriteria
{
    /**
     * @param  list<string>|null  $platforms  ios, android, web, ...
     * @param  list<string>|null  $customerIds  segment result from our side
     */
    public function __construct(
        public ?array $platforms = null,
        public ?int $activeWithinDays = null,
        public ?array $customerIds = null,
        public bool $requireResolvedIdentity = false,
        public bool $testRecipientsOnly = false,
    ) {}
}
