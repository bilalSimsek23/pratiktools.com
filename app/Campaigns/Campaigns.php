<?php

namespace App\Campaigns;

use App\Approvals\CampaignApprovals;
use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Segments\Segments;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campaign lifecycle on top of F4 (templates, sender identities) and F5
 * (segments, snapshots). Becoming ready freezes the audience (a snapshot
 * unique per campaign), the published content version and the sender; a
 * database trigger keeps them unchanged afterwards. No state starts a
 * send: there is no dispatcher in F6.
 */
final class Campaigns
{
    public const TYPES = ['newsletter', 'promotional', 'announcement'];

    private const EDITABLE = ['name', 'type', 'audience_source', 'segment_id', 'segment_snapshot_id', 'template_version_id', 'integration_id', 'sender_identity_id', 'settings'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Segments $segments,
        private readonly CampaignReadiness $readiness,
        private readonly CampaignApprovals $approvals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Actor $actor): string
    {
        $values = $this->validate($attributes, true);
        $id = (string) Str::uuid7();
        DB::table('campaigns')->insert($values + ['id' => $id, 'tenant_id' => $this->context->requireTenant(), 'status' => CampaignStatus::Draft->value,
            'created_by' => self::by($actor), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record(AuditAction::CampaignCreated, 'campaign', $id, ['type' => $values['type'], 'audience_source' => $values['audience_source']], $actor);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $campaignId, array $attributes, Actor $actor): void
    {
        DB::transaction(function () use ($campaignId, $attributes, $actor) {
            $campaign = $this->lock($campaignId);
            if ($campaign->status !== CampaignStatus::Draft->value || $campaign->frozen_at !== null) {
                throw new CampaignError('NOT_EDITABLE', 'Only draft campaigns can be edited.');
            }
            $values = $this->validate($attributes + (array) $campaign, false);
            // The freeze generation is system state, never user input.
            $stored = (array) json_decode((string) $campaign->settings, true);
            $values['settings'] = json_encode((object) ((array) json_decode($values['settings'], true) + array_intersect_key($stored, ['reopen_count' => true])));
            DB::table('campaigns')->where('tenant_id', $campaign->tenant_id)->where('id', $campaignId)->update($values + ['updated_at' => now()]);
            $this->approvals->invalidateStale($this->find($campaignId), $actor);
            $this->audit->record(AuditAction::CampaignChanged, 'campaign', $campaignId, ['fields' => array_values(array_intersect(array_keys($attributes), self::EDITABLE))], $actor);
        });
    }

    /**
     * @return array<string, mixed> the readiness evaluation that allowed (or refused) the change
     */
    public function transition(string $campaignId, CampaignStatus $to, Actor $actor, ?CarbonImmutable $scheduledAt = null): array
    {
        return DB::transaction(function () use ($campaignId, $to, $actor, $scheduledAt) {
            $campaign = $this->lock($campaignId);
            $from = CampaignStatus::from($campaign->status);
            if (! $from->canBecome($to)) {
                throw new CampaignError('INVALID_TRANSITION', "Cannot go from {$from->value} to {$to->value}.");
            }
            $readiness = ['checks' => []];
            $values = ['status' => $to->value, 'updated_at' => now()];

            if ($to === CampaignStatus::Ready || $to === CampaignStatus::Scheduled) {
                $readiness = $this->readiness->evaluate($campaign);
                if (! $readiness['can_prepare']) {
                    throw new CampaignError('NOT_READY', 'Campaign preparation checks failed: '.implode(', ', $readiness['reason_codes']).'.', $readiness['reason_codes']);
                }
            }
            if ($to === CampaignStatus::Ready) {
                // One snapshot per freeze generation: a retry reuses it, a reopen starts a new one.
                $reopens = (int) (json_decode((string) $campaign->settings, true)['reopen_count'] ?? 0);
                $snapshot = $campaign->segment_snapshot_id ?? $this->segments->snapshot((string) $campaign->segment_id, $actor, "campaign:{$campaignId}".($reopens > 0 ? ":r{$reopens}" : ''));
                $values += ['segment_snapshot_id' => $snapshot, 'frozen_at' => now(), 'published_by' => self::by($actor)];
                $this->audit->record(AuditAction::CampaignAudienceFrozen, 'campaign', $campaignId, ['snapshot_id' => $snapshot, 'members' => $this->segments->snapshotCount($snapshot)], $actor);
            }
            if ($to === CampaignStatus::Scheduled) {
                if ($scheduledAt === null || $scheduledAt->isPast()) {
                    throw new CampaignError('INVALID_SCHEDULE', 'A future scheduled_at is required.');
                }
                $values['scheduled_at'] = $scheduledAt;
            }

            DB::table('campaigns')->where('tenant_id', $campaign->tenant_id)->where('id', $campaignId)->update($values);
            if (in_array($to, [CampaignStatus::Cancelled, CampaignStatus::Archived], true)) {
                $this->approvals->invalidateStale($campaign, $actor, all: true, reason: 'campaign_'.$to->value);
                $this->cancelOpenDispatches($campaign, $actor, 'campaign_'.$to->value);
            }
            $this->audit->record(AuditAction::CampaignStateChanged, 'campaign', $campaignId, ['from' => $from->value, 'to' => $to->value,
                'blocked' => array_values(array_map(fn ($c) => $c['reason'], array_filter($readiness['checks'], fn ($c) => $c['status'] === 'BLOCKED')))], $actor);

            return $readiness;
        });
    }

    /**
     * Back to draft for changes: unfreezes audience, content and sender (the
     * only path that may change them) and invalidates every approval.
     * Allowed only from ready (never once scheduled).
     */
    public function reopen(string $campaignId, Actor $actor): void
    {
        DB::transaction(function () use ($campaignId, $actor) {
            $campaign = $this->lock($campaignId);
            if ($campaign->status !== CampaignStatus::Ready->value) {
                throw new CampaignError('INVALID_TRANSITION', 'Only a ready campaign can be reopened.');
            }
            if (DB::table('campaign_dispatches')->where('tenant_id', $campaign->tenant_id)->where('campaign_id', $campaignId)->whereIn('status', ['queued', 'running'])->exists()) {
                throw new CampaignError('DISPATCH_ACTIVE', 'A dispatch is in progress.');
            }
            DB::table('campaigns')->where('tenant_id', $campaign->tenant_id)->where('id', $campaignId)->update([
                'status' => CampaignStatus::Draft->value, 'frozen_at' => null, 'published_by' => null, 'updated_at' => now(),
                'segment_snapshot_id' => $campaign->audience_source === 'segment' ? null : $campaign->segment_snapshot_id,
                'settings' => json_encode(['reopen_count' => (int) (json_decode((string) $campaign->settings, true)['reopen_count'] ?? 0) + 1] + (array) json_decode((string) $campaign->settings, true)),
            ]);
            $this->approvals->invalidateStale($campaign, $actor, all: true, reason: 'campaign_reopened');
            $this->cancelOpenDispatches($campaign, $actor, 'campaign_reopened');
            $this->audit->record(AuditAction::CampaignReopened, 'campaign', $campaignId, [], $actor);
        });
    }

    /**
     * Dispatches not yet queued end with the campaign state they belonged to.
     */
    private function cancelOpenDispatches(object $campaign, Actor $actor, string $reason): void
    {
        $open = DB::table('campaign_dispatches')->where('tenant_id', $campaign->tenant_id)->where('campaign_id', $campaign->id)
            ->whereIn('status', ['draft', 'awaiting_approval', 'approved', 'blocked', 'failed'])->lockForUpdate()->pluck('id');
        foreach ($open as $id) {
            DB::table('campaign_dispatches')->where('tenant_id', $campaign->tenant_id)->where('id', $id)
                ->update(['status' => 'cancelled', 'block_reason' => mb_substr($reason, 0, 64), 'updated_at' => now()]);
            $this->audit->record(AuditAction::DispatchCancelled, 'campaign_dispatch', (string) $id, ['reason' => $reason], $actor);
        }
    }

    public function find(string $campaignId): object
    {
        return DB::table('campaigns')->where('tenant_id', $this->context->requireTenant())->where('id', $campaignId)->first()
            ?? throw new CampaignError('NOT_FOUND', 'Unknown campaign.');
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function validate(array $a, bool $creating): array
    {
        $tenantId = $this->context->requireTenant();
        $name = trim((string) ($a['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new CampaignError('INVALID_NAME', 'A name of up to 160 characters is required.');
        }
        if (! in_array($a['type'] ?? null, self::TYPES, true)) {
            throw new CampaignError('INVALID_TYPE', 'type is newsletter, promotional or announcement.');
        }
        $source = $a['audience_source'] ?? 'segment';
        if (! in_array($source, ['segment', 'snapshot'], true)) {
            throw new CampaignError('INVALID_AUDIENCE', 'audience_source is segment or snapshot.');
        }
        $exists = fn (string $table, mixed $id) => $id === null || (is_string($id) && Str::isUuid($id) && DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->exists());
        if ($source === 'segment' && ! (($a['segment_id'] ?? null) !== null && $exists('segments', $a['segment_id']))) {
            throw new CampaignError('INVALID_AUDIENCE', 'Unknown segment.');
        }
        if ($source === 'snapshot' && ! (($a['segment_snapshot_id'] ?? null) !== null && $exists('segment_snapshots', $a['segment_snapshot_id']))) {
            throw new CampaignError('INVALID_AUDIENCE', 'Unknown segment snapshot.');
        }
        foreach (['template_version_id' => 'email_template_versions', 'sender_identity_id' => 'email_sender_identities', 'integration_id' => 'tenant_integrations'] as $field => $table) {
            if (! $exists($table, $a[$field] ?? null)) {
                throw new CampaignError('INVALID_REFERENCE', "Unknown {$field}.");
            }
        }
        $settings = $a['settings'] ?? [];
        if (is_string($settings)) {
            $settings = (array) json_decode($settings, true);
        }

        return [
            'name' => $name, 'type' => $a['type'], 'audience_source' => $source,
            'segment_id' => $a['segment_id'] ?? null, 'segment_snapshot_id' => $source === 'snapshot' ? $a['segment_snapshot_id'] : null,
            'template_version_id' => $a['template_version_id'] ?? null, 'integration_id' => $a['integration_id'] ?? null, 'sender_identity_id' => $a['sender_identity_id'] ?? null,
            'settings' => json_encode((object) array_intersect_key((array) $settings, array_flip(['notes', 'utm_campaign']))),
        ];
    }

    private function lock(string $campaignId): object
    {
        return DB::table('campaigns')->where('tenant_id', $this->context->requireTenant())->where('id', $campaignId)->lockForUpdate()->first()
            ?? throw new CampaignError('NOT_FOUND', 'Unknown campaign.');
    }

    private static function by(Actor $actor): string
    {
        return mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64);
    }
}
