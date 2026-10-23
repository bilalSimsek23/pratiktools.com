<?php

namespace App\Connectors\Iys;

use App\Consent\Data\ProviderConsent;
use App\Consent\Iys\IysTerms;
use App\Consent\Sync\ConsentSynchronizer;
use App\Models\WebhookInboxItem;
use App\Webhooks\Contracts\WebhookHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies pushed İYS movements (recipient-made, "IYS_" sources) as
 * observations. Idempotent through the transaction id, like the pull.
 */
final class IysPushHandler implements WebhookHandler
{
    public function __construct(private readonly ConsentSynchronizer $sync) {}

    public function handle(WebhookInboxItem $item): void
    {
        $list = $item->payload['list'] ?? [];
        if (! is_array($list)) {
            return;
        }

        DB::transaction(function () use ($list) {
            foreach ($list as $row) {
                if (! is_array($row) || ! isset($row['recipient'], $row['type'], $row['status'])) {
                    continue;
                }
                // İYS sends this fixed test movement when a connection is created.
                if ($row['recipient'] === 'pushtest@iys.org.tr') {
                    continue;
                }

                $this->sync->observeIys(new ProviderConsent(
                    recipient: (string) $row['recipient'],
                    channel: IysTerms::channel((string) $row['type']),
                    recipientType: IysTerms::recipientType((string) ($row['recipientType'] ?? 'BIREYSEL')),
                    status: IysTerms::status((string) $row['status']),
                    source: isset($row['source']) ? (string) $row['source'] : null,
                    consentDate: isset($row['consentDate']) ? CarbonImmutable::parse((string) $row['consentDate'], 'Europe/Istanbul')->utc() : null,
                    creationDate: isset($row['creationDate']) ? CarbonImmutable::parse((string) $row['creationDate'], 'Europe/Istanbul')->utc() : null,
                    transactionId: isset($row['transactionId']) ? (string) $row['transactionId'] : null,
                ), 'push');
            }
        });
    }
}
