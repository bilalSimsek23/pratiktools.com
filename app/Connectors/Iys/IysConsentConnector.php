<?php

namespace App\Connectors\Iys;

use App\Consent\ConsentChannel;
use App\Consent\Contracts\ConsentProvider;
use App\Consent\Data\ConsentChangesPage;
use App\Consent\Data\ProviderConsent;
use App\Consent\Iys\IysTerms;
use App\Consent\RecipientType;
use App\Integrations\Errors\ProviderError;
use Carbon\CarbonImmutable;

/**
 * İYS implementation of ConsentProvider (İYS API 3.2.2). Read-only.
 *
 * Facts from the official API document that shape this class:
 * - bulk status takes at most 1,000 addresses and returns only the ones in
 *   ONAY; RET and "no record" are indistinguishable there;
 * - single status allows 1,000 requests per hour per IP;
 * - consent changes cover the last 7 days minus the latest hour, ordered
 *   by creation date, paged with an opaque "after" cursor (1,000 per page),
 *   separately for recipient-made (source=IYS) and provider-sent
 *   (source=HS) movements;
 * - dates are "YYYY-MM-DD HH:MM:SS" in Turkish local time.
 */
final class IysConsentConnector implements ConsentProvider
{
    public const BULK_LIMIT = 1000;

    private const TIMEZONE = 'Europe/Istanbul';

    public function __construct(private readonly IysClient $client) {}

    public function client(): IysClient
    {
        return $this->client;
    }

    public function authenticate(): array
    {
        $brands = (array) $this->client->call('brands')->json();
        $list = array_is_list($brands) ? $brands : [$brands];
        $brandCode = $this->client->credentials()->brandCode;

        $brand = collect($list)->first(fn ($b) => is_array($b) && (int) ($b['brandCode'] ?? 0) === $brandCode);
        if ($brand === null) {
            throw ProviderError::auth('The API user has no access to the configured İYS brand code.', 'brands');
        }

        return ['brand_name' => $brand['name'] ?? null, 'brand_code' => $brandCode, 'is_master' => $brand['master'] ?? null];
    }

    public function queryConsent(string $recipient, ConsentChannel $channel, RecipientType $type): ?ProviderConsent
    {
        $response = $this->client->call('consent_status', json: [
            'recipient' => $recipient,
            'recipientType' => IysTerms::toIysRecipientType($type),
            'type' => IysTerms::toIysChannel($channel),
        ]);

        // The document defines no "not found" answer for this call; an
        // empty body is treated as "no record". To be confirmed in sandbox.
        $body = $response->json();
        if ($response->status() === 204 || ! is_array($body) || ! isset($body['status'])) {
            return null;
        }

        return $this->consent($body);
    }

    public function bulkQueryGranted(array $recipients, ConsentChannel $channel, RecipientType $type): array
    {
        if (count($recipients) > self::BULK_LIMIT) {
            throw new \InvalidArgumentException('İYS bulk status accepts at most '.self::BULK_LIMIT.' addresses.');
        }
        if ($recipients === []) {
            return [];
        }

        $response = $this->client->call('consent_status_bulk', [
            'recipientType' => IysTerms::toIysRecipientType($type),
            'type' => IysTerms::toIysChannel($channel),
        ], json: ['recipients' => $recipients]);

        $list = $response->json('list');
        if (! is_array($list)) {
            throw ProviderError::schema('İYS bulk status response has no list.', 'consent_status_bulk');
        }

        return array_values(array_filter($list, 'is_string'));
    }

    public function bulkBatchSize(): int
    {
        return self::BULK_LIMIT;
    }

    public function getChanges(?string $after, string $origin): ConsentChangesPage
    {
        $response = $this->client->call('consent_changes', query: array_filter([
            'source' => $origin === 'provider' ? 'HS' : 'IYS',
            'limit' => self::BULK_LIMIT,
            'after' => $after,
        ], fn ($v) => $v !== null));

        if ($response->status() === 204) {
            return new ConsentChangesPage([], $after);
        }

        $list = $response->json('list') ?? [];
        if (! is_array($list)) {
            throw ProviderError::schema('İYS changes response has no list.', 'consent_changes');
        }

        return new ConsentChangesPage(
            array_map(fn (array $c) => $this->consent($c), array_values(array_filter($list, 'is_array'))),
            is_string($response->json('after')) ? $response->json('after') : $after,
        );
    }

    public function getConsentHistory(string $recipient, ConsentChannel $channel, RecipientType $type): array
    {
        $out = [];
        for ($offset = 0; ; $offset += 100) {
            $response = $this->client->call('consent_history', query: ['limit' => 100, 'offset' => $offset], json: [
                'recipient' => $recipient,
                'recipientType' => IysTerms::toIysRecipientType($type),
                'type' => IysTerms::toIysChannel($channel),
            ]);
            $list = $response->json('list');
            if (! is_array($list) || $list === []) {
                return $out;
            }
            foreach ($list as $row) {
                if (is_array($row)) {
                    $out[] = $this->consent($row);
                }
            }
            if (count($list) < 100 || $offset + 100 >= (int) $response->json('pagination.totalCount', 0)) {
                return $out;
            }
        }
    }

    public function getDailySummary(CarbonImmutable $date, RecipientType $type): array
    {
        $response = $this->client->call('daily_summary', [
            'reportDate' => $date->setTimezone(self::TIMEZONE)->toDateString(),
            'recipientType' => IysTerms::toIysRecipientType($type),
        ]);

        $stats = [];
        foreach ((array) $response->json('changeStats') as $row) {
            if (! is_array($row) || ! isset($row['type'])) {
                continue;
            }
            foreach ((array) ($row['consents'] ?? []) as $c) {
                $stats[IysTerms::channel((string) $row['type'])->value][(string) ($c['source'] ?? 'unknown')] = [
                    'granted' => (int) ($c['approval'] ?? 0),
                    'revoked' => (int) ($c['rejection'] ?? 0),
                    'pending' => (int) ($c['pending'] ?? 0),
                    'total' => (int) ($c['total'] ?? 0),
                ];
            }
        }

        return $stats;
    }

    public function getQuota(): array
    {
        $body = (array) $this->client->call('subscription')->json();

        return array_intersect_key($body, array_flip(['status', 'subscriptionPackageName', 'startDate', 'expirationDate', 'consentLimit', 'usedConsentLimit', 'consentLimitExceed']));
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function consent(array $c): ProviderConsent
    {
        return new ProviderConsent(
            recipient: (string) ($c['recipient'] ?? throw ProviderError::schema('İYS consent without recipient.')),
            channel: IysTerms::channel((string) ($c['type'] ?? '')),
            recipientType: IysTerms::recipientType((string) ($c['recipientType'] ?? 'BIREYSEL')),
            status: IysTerms::status((string) ($c['status'] ?? '')),
            source: isset($c['source']) ? (string) $c['source'] : null,
            consentDate: $this->date($c['consentDate'] ?? null),
            creationDate: $this->date($c['creationDate'] ?? null),
            transactionId: isset($c['transactionId']) ? (string) $c['transactionId'] : null,
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::TIMEZONE)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
