<?php

namespace App\Commerce\Data;

use Carbon\CarbonImmutable;

/**
 * A person known to the commerce platform: a registered member or a
 * newsletter-only subscriber. Permission flags are source data only.
 */
final readonly class CustomerRecord
{
    public const KIND_MEMBER = 'member';

    public const KIND_SUBSCRIBER = 'subscriber';

    /**
     * @param  list<string>  $groups
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $kind,
        public string $externalId,
        public ?string $email,
        public ?string $phone,
        public ?string $firstName,
        public ?string $lastName,
        public ?CarbonImmutable $birthDate = null,
        public ?string $gender = null,
        public ?string $city = null,
        public ?string $district = null,
        public ?CarbonImmutable $memberSince = null,
        public ?bool $isActive = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?bool $emailOptIn = null,
        public ?bool $smsOptIn = null,
        public ?CarbonImmutable $permissionUpdatedAt = null,
        public array $groups = [],
        public array $attributes = [],
    ) {}
}
