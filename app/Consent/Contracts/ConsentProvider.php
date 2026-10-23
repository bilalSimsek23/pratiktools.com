<?php

namespace App\Consent\Contracts;

use App\Consent\ConsentChannel;
use App\Consent\Data\ConsentChangesPage;
use App\Consent\Data\ProviderConsent;
use App\Consent\RecipientType;
use Carbon\CarbonImmutable;

/**
 * Read-only access to an authoritative consent registry (İYS first).
 * Implementations never change consent state; writes are a separate,
 * explicitly enabled capability that does not exist yet.
 */
interface ConsentProvider
{
    /**
     * Verifies credentials; returns provider account facts (no secrets).
     *
     * @return array<string, mixed>
     */
    public function authenticate(): array;

    /**
     * Full status of one address, or null when the registry has no
     * record for it.
     */
    public function queryConsent(string $recipient, ConsentChannel $channel, RecipientType $type): ?ProviderConsent;

    /**
     * Which of the given addresses are GRANTED (at most the provider's
     * batch size). Addresses not returned are not granted: revoked or
     * unknown to the registry, which this call cannot tell apart.
     *
     * @param  list<string>  $recipients
     * @return list<string>
     */
    public function bulkQueryGranted(array $recipients, ConsentChannel $channel, RecipientType $type): array;

    public function bulkBatchSize(): int;

    /**
     * Consent movements after an opaque cursor, oldest first.
     *
     * @param  'citizen'|'provider'  $origin  changes made by recipients, or sent by the service provider
     */
    public function getChanges(?string $after, string $origin): ConsentChangesPage;

    /**
     * @return list<ProviderConsent> newest first
     */
    public function getConsentHistory(string $recipient, ConsentChannel $channel, RecipientType $type): array;

    /**
     * Daily movement counts for reconciliation.
     *
     * @return array<string, mixed>
     */
    public function getDailySummary(CarbonImmutable $date, RecipientType $type): array;

    /**
     * @return array<string, mixed>
     */
    public function getQuota(): array;
}
