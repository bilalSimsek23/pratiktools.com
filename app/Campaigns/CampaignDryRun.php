<?php

namespace App\Campaigns;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Segments\ReadOnlyTransaction;

/**
 * Side-effect-free campaign evaluation: audience counts, readiness and how
 * many e-mails would be planned. Runs inside a READ ONLY transaction; no
 * email_messages, no provider call, no job.
 */
final class CampaignDryRun
{
    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly CampaignAudience $audience,
        private readonly CampaignReadiness $readiness,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $campaignId, ?Actor $actor = null): array
    {
        $result = ReadOnlyTransaction::run(fn (): array => $this->evaluate($campaignId));

        $this->audit->record(AuditAction::CampaignDryRun, 'campaign', $campaignId, array_intersect_key($result, array_flip(['raw_audience', 'consent_allowed', 'currently_eligible', 'planned_email'])), $actor);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(string $campaignId): array
    {
        $campaign = $this->campaigns->find($campaignId);
        $report = $this->audience->compute($campaign);
        $readiness = $this->readiness->evaluate($campaign, $report);
        $byCheck = array_column($readiness['checks'], 'status', 'check');
        $senderOk = ($byCheck['SENDER'] ?? null) === 'PASS' && ($byCheck['DKIM'] ?? null) === 'PASS';
        $unsubscribeOk = ($byCheck['UNSUBSCRIBE'] ?? null) === 'PASS';

        return array_merge([
            'campaign_id' => $campaignId,
            'status' => $campaign->status,
            'snapshot_size' => $report['audience_frozen'] ? $report['raw_audience'] : null,
        ], $report, [
            'sender_blocked' => ! $senderOk,
            'unsubscribe_blocked' => ! $unsubscribeOk,
            'planned_email' => $senderOk && $unsubscribeOk && ($byCheck['CONTENT'] ?? null) === 'PASS' ? $report['currently_eligible'] : 0,
            'readiness' => $readiness,
            'provider_calls' => 0,
            'send_jobs' => 0,
        ]);

    }
}
