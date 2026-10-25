<?php

namespace App\Approvals;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Notifications\TenantNotifications;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two-person campaign approvals.
 *
 * - An approval binds to the campaign fingerprint (frozen audience snapshot,
 *   content version, sender, integration, type). If any of them changes the
 *   approval no longer counts and is invalidated.
 * - Slots "review" and "approve" are decided by two different people; the
 *   person who requested approval (the campaign's preparer) decides
 *   neither. The database refuses requester = decider and keeps decided
 *   approvals immutable (only expired/invalidated may follow approved).
 * - Approvals expire after dispatch.approval_ttl_days.
 * - No bypass: no role, Super Admin or break-glass path decides or skips them.
 */
final class CampaignApprovals
{
    public const SLOTS = ['review', 'approve'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly TenantNotifications $notifications,
    ) {}

    public static function fingerprint(object $campaign): string
    {
        return hash('sha256', (string) json_encode([
            'snapshot' => $campaign->segment_snapshot_id, 'content' => $campaign->template_version_id, 'sender' => $campaign->sender_identity_id,
            'integration' => $campaign->integration_id, 'audience_source' => $campaign->audience_source, 'segment' => $campaign->segment_id, 'type' => $campaign->type,
        ]));
    }

    /**
     * Open both slots for the current (frozen) fingerprint.
     *
     * @return list<string> approval ids (existing open ones are kept)
     */
    public function request(object $campaign, string $userId): array
    {
        if (! in_array($campaign->status, ['ready', 'scheduled'], true) || $campaign->frozen_at === null) {
            throw new ApprovalError('NOT_FROZEN', 'Approvals are requested for a ready campaign (frozen audience, content and sender).');
        }
        $tenantId = $this->context->requireTenant();
        $fingerprint = self::fingerprint($campaign);
        $this->invalidateStale($campaign, Actor::user($userId));

        foreach (self::SLOTS as $slot) {
            $inserted = DB::affectingStatement(
                "INSERT INTO campaign_approvals (id, tenant_id, campaign_id, fingerprint, slot, requested_by, status, requested_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', now(), now() + make_interval(days => ?)) ON CONFLICT DO NOTHING",
                [(string) Str::uuid7(), $tenantId, $campaign->id, $fingerprint, $slot, $userId, (int) config('dispatch.approval_ttl_days')],
            );
            if ($inserted === 1) {
                $this->audit->record(AuditAction::CampaignApprovalRequested, 'campaign', $campaign->id, ['slot' => $slot], Actor::user($userId));
            }
        }
        $this->notifications->notify(Permission::CampaignsApprove, 'approval_requested', 'campaign', $campaign->id, "Onay bekleniyor: {$campaign->name}");

        return DB::table('campaign_approvals')->where('tenant_id', $tenantId)->where('campaign_id', $campaign->id)->where('fingerprint', $fingerprint)
            ->whereIn('status', ['pending', 'approved'])->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function decide(string $approvalId, object $campaign, string $userId, bool $approve, ?string $reason): void
    {
        DB::transaction(function () use ($approvalId, $campaign, $userId, $approve, $reason) {
            $tenantId = $this->context->requireTenant();
            $a = DB::table('campaign_approvals')->where('tenant_id', $tenantId)->where('id', $approvalId)->where('campaign_id', $campaign->id)->lockForUpdate()->first()
                ?? throw new ApprovalError('NOT_FOUND', 'Unknown approval.');
            if ($a->status !== 'pending') {
                throw new ApprovalError('ALREADY_DECIDED', "This approval is {$a->status}.");
            }
            if (now()->gte($a->expires_at)) {
                DB::table('campaign_approvals')->where('id', $a->id)->update(['status' => 'expired', 'closed_at' => now(), 'close_reason' => 'ttl']);
                throw new ApprovalError('EXPIRED', 'This approval request expired.');
            }
            if ($a->fingerprint !== self::fingerprint($campaign)) {
                DB::table('campaign_approvals')->where('id', $a->id)->update(['status' => 'invalidated', 'closed_at' => now(), 'close_reason' => 'campaign_changed']);
                throw new ApprovalError('INVALIDATED', 'The campaign changed since approval was requested.');
            }
            if ($userId === $a->requested_by || $userId === self::creatorUserId($campaign)) {
                throw new ApprovalError('SELF_APPROVAL', 'You cannot approve a campaign you prepared or requested.');
            }
            $otherSlot = DB::table('campaign_approvals')->where('tenant_id', $tenantId)->where('campaign_id', $campaign->id)->where('fingerprint', $a->fingerprint)
                ->where('id', '<>', $a->id)->where('status', 'approved')->value('decided_by');
            if ($approve && $otherSlot === $userId) {
                throw new ApprovalError('SAME_PERSON', 'The second approval must come from a different person.');
            }
            if (! $approve && trim((string) $reason) === '') {
                throw new ApprovalError('REASON_REQUIRED', 'A rejection needs a reason.');
            }

            DB::table('campaign_approvals')->where('id', $a->id)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $userId, 'decided_at' => now(),
                'reason' => $reason === null ? null : mb_substr($reason, 0, 500)]);
            $this->audit->record($approve ? AuditAction::CampaignApproved : AuditAction::CampaignRejected, 'campaign', $campaign->id,
                ['slot' => $a->slot, 'approval_id' => $a->id, 'reason' => $reason === null ? null : mb_substr($reason, 0, 120)], Actor::user($userId));
            $this->notifications->notify(Permission::CampaignsView, $approve ? 'approval_given' : 'approval_rejected', 'campaign', $campaign->id,
                ($approve ? 'Onaylandı' : 'Reddedildi')." ({$a->slot}): {$campaign->name}", $a->requested_by);
        });
    }

    /**
     * Invalidate open approvals of other fingerprints (or all, on reopen).
     */
    public function invalidateStale(object $campaign, Actor $actor, bool $all = false, string $reason = 'campaign_changed'): int
    {
        $q = DB::table('campaign_approvals')->where('tenant_id', $this->context->requireTenant())->where('campaign_id', $campaign->id)->whereIn('status', ['pending', 'approved']);
        if (! $all) {
            $q->where('fingerprint', '<>', self::fingerprint($campaign));
        }
        $n = $q->update(['status' => 'invalidated', 'closed_at' => now(), 'close_reason' => mb_substr($reason, 0, 48)]);
        if ($n > 0) {
            $this->audit->record(AuditAction::CampaignApprovalsInvalidated, 'campaign', $campaign->id, ['count' => $n, 'reason' => $reason], $actor);
        }

        return $n;
    }

    /**
     * Valid approvals for the current fingerprint: approved, unexpired,
     * by distinct people other than the creator and the given requester.
     *
     * @return Collection<int, \stdClass>
     */
    public function valid(object $campaign, ?string $excludeUserId = null): Collection
    {
        $tenantId = $this->context->requireTenant();
        DB::table('campaign_approvals')->where('tenant_id', $tenantId)->where('campaign_id', $campaign->id)->whereIn('status', ['pending', 'approved'])
            ->where('expires_at', '<=', now())->update(['status' => 'expired', 'closed_at' => now(), 'close_reason' => 'ttl']);
        $creator = self::creatorUserId($campaign);

        return DB::table('campaign_approvals')->where('tenant_id', $tenantId)->where('campaign_id', $campaign->id)->where('fingerprint', self::fingerprint($campaign))
            ->where('status', 'approved')->orderBy('decided_at')->get()
            ->reject(fn ($a) => $a->decided_by === $creator || ($excludeUserId !== null && $a->decided_by === $excludeUserId))
            ->unique('decided_by')->values();
    }

    public function rejected(object $campaign): bool
    {
        return DB::table('campaign_approvals')->where('tenant_id', $this->context->requireTenant())->where('campaign_id', $campaign->id)
            ->where('fingerprint', self::fingerprint($campaign))->where('status', 'rejected')->exists();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function history(string $campaignId): Collection
    {
        return DB::table('campaign_approvals')->where('tenant_id', $this->context->requireTenant())->where('campaign_id', $campaignId)->orderByDesc('requested_at')->get();
    }

    private static function creatorUserId(object $campaign): ?string
    {
        return str_starts_with((string) $campaign->created_by, 'user:') ? substr((string) $campaign->created_by, 5) : null;
    }
}
