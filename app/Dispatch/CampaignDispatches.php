<?php

namespace App\Dispatch;

use App\Approvals\CampaignApprovals;
use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Campaigns\Campaigns;
use App\Email\Data\EmailDraft;
use App\Email\EmailDelivery;
use App\Email\EmailMessageType;
use App\Email\Templates\EmailTemplates;
use App\Jobs\ExecuteCampaignDispatch;
use App\Models\TenantIntegration;
use App\Push\PushDelivery;
use App\Push\PushMessage;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Controlled campaign dispatch (test-only in this phase).
 *
 * A dispatch request is an immutable record of who asked to send which
 * frozen campaign (snapshot, fingerprint) on which channel, in which mode,
 * to which allowlisted test recipients. Its idempotency key is
 * tenant + snapshot + channel + mode + generation (unique index): asking
 * again returns the same request; a new generation is an explicit operator
 * action. Execution hands messages to the F4/F3 delivery pipelines with
 * per-recipient idempotency keys, so a retry continues instead of
 * duplicating. Cancelled and completed requests are final (DB trigger).
 */
final class CampaignDispatches
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Campaigns $campaigns,
        private readonly DispatchGuard $guard,
        private readonly DispatchPlanner $planner,
        private readonly EmailTemplates $templates,
        private readonly EmailDelivery $email,
        private readonly PushDelivery $push,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<array-key, mixed>  $recipientIds  test_recipients ids from the allowlist (user input, checked)
     * @param  array{title?: string, body?: string}  $pushContent
     */
    public function request(string $campaignId, string $channel, string $mode, array $recipientIds, string $userId, array $pushContent = [], bool $newGeneration = false): string
    {
        if (! in_array($channel, ['email', 'push'], true)) {
            throw new InvalidArgumentException('channel is email or push.');
        }
        $this->guard->assertMode($mode, $channel, sendingMode: false);
        $recipientIds = array_values(array_unique(array_map('strval', array_filter($recipientIds, fn ($id) => is_string($id) && Str::isUuid($id)))));
        $max = $channel === 'email' ? (int) config('email.max_test_recipients') : (int) config('push.max_test_recipients');
        if ($recipientIds === [] || count($recipientIds) > $max) {
            throw new DispatchBlocked('RECIPIENT_COUNT', "Choose between 1 and {$max} test recipients from the allowlist.");
        }
        $this->guard->assertRecipients($channel, $recipientIds);
        $content = $channel === 'push' ? $this->pushContent($pushContent) : ['subject_prefix' => '[TEST] '];

        return DB::transaction(function () use ($campaignId, $channel, $mode, $recipientIds, $userId, $content, $newGeneration) {
            $tenantId = $this->context->requireTenant();
            $campaign = DB::table('campaigns')->where('tenant_id', $tenantId)->where('id', $campaignId)->lockForUpdate()->first()
                ?? throw new DispatchBlocked('NOT_FOUND', 'Unknown campaign.');
            if (! in_array($campaign->status, ['ready', 'scheduled'], true) || $campaign->segment_snapshot_id === null) {
                throw new DispatchBlocked('CAMPAIGN_NOT_FROZEN', 'Only a ready campaign (frozen) can be dispatched.');
            }
            $latest = (int) DB::table('campaign_dispatches')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
                ->where('segment_snapshot_id', $campaign->segment_snapshot_id)->where('channel', $channel)->where('mode', $mode)->max('generation');
            $generation = $newGeneration ? $latest + 1 : max(1, $latest);
            $key = hash('sha256', implode('|', [$tenantId, $campaign->segment_snapshot_id, $channel, $mode, $generation]));
            $existing = DB::table('campaign_dispatches')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->value('id');
            if ($existing !== null) {
                return (string) $existing;
            }

            $id = (string) Str::uuid7();
            $plan = $this->planner->plan($campaign, $channel, $recipientIds);
            $approved = $this->guard->approvalsSatisfied($campaign, $mode, $userId);
            DB::table('campaign_dispatches')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'segment_snapshot_id' => $campaign->segment_snapshot_id,
                'fingerprint' => CampaignApprovals::fingerprint($campaign), 'channel' => $channel, 'mode' => $mode, 'generation' => $generation, 'idempotency_key' => $key,
                'status' => $approved ? 'approved' : 'awaiting_approval', 'requested_by' => $userId, 'recipients' => json_encode($recipientIds), 'plan' => json_encode($plan),
                'content' => json_encode($content), 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record(AuditAction::DispatchRequested, 'campaign_dispatch', $id, ['campaign_id' => $campaignId, 'channel' => $channel, 'mode' => $mode,
                'generation' => $generation, 'recipients' => count($recipientIds), 'eligible' => $plan['recipients_eligible']], Actor::user($userId));

            return $id;
        });
    }

    /**
     * Re-evaluate approvals of a waiting request.
     */
    public function refresh(string $dispatchId): object
    {
        $d = $this->find($dispatchId);
        if (in_array($d->status, ['awaiting_approval', 'blocked'], true)) {
            $campaign = $this->campaigns->find($d->campaign_id);
            if ($this->guard->approvalsSatisfied($campaign, $d->mode, $d->requested_by) && $d->fingerprint === CampaignApprovals::fingerprint($campaign)) {
                DB::table('campaign_dispatches')->where('tenant_id', $d->tenant_id)->where('id', $d->id)->update(['status' => 'approved', 'block_reason' => null, 'updated_at' => now()]);
                $this->audit->record(AuditAction::DispatchApproved, 'campaign_dispatch', $d->id, [], Actor::system('dispatch'));
            }
        }

        return $this->find($dispatchId);
    }

    public function queue(string $dispatchId, string $userId): void
    {
        $d = $this->refresh($dispatchId);
        if (! in_array($d->status, ['approved', 'failed'], true)) {
            throw new DispatchBlocked('INVALID_STATE', "A {$d->status} dispatch cannot be queued.");
        }
        try {
            $this->guard->assert($d, $this->campaigns->find($d->campaign_id));
        } catch (DispatchBlocked $e) {
            $this->setStatus($d, 'blocked', $e->reason);
            $this->audit->record(AuditAction::DispatchBlocked, 'campaign_dispatch', $d->id, ['reason' => $e->reason], Actor::user($userId));
            throw $e;
        }
        $this->setStatus($d, 'queued', null);
        $this->audit->record(AuditAction::DispatchQueued, 'campaign_dispatch', $d->id, ['channel' => $d->channel, 'mode' => $d->mode, 'retry' => $d->status === 'failed'], Actor::user($userId));
        ExecuteCampaignDispatch::dispatch($d->id, $d->tenant_id);
    }

    public function cancel(string $dispatchId, string $userId): void
    {
        $d = $this->find($dispatchId);
        if (! in_array($d->status, ['draft', 'awaiting_approval', 'approved', 'queued', 'blocked', 'failed'], true)) {
            throw new DispatchBlocked('INVALID_STATE', "A {$d->status} dispatch cannot be cancelled.");
        }
        DB::table('campaign_dispatches')->where('tenant_id', $d->tenant_id)->where('id', $d->id)->update(['status' => 'cancelled', 'cancelled_by' => $userId, 'updated_at' => now()]);
        $this->audit->record(AuditAction::DispatchCancelled, 'campaign_dispatch', $d->id, ['from' => $d->status], Actor::user($userId));
    }

    /**
     * Job body: guard again, then hand each allowlisted recipient to the
     * delivery pipeline once (per-recipient idempotency).
     */
    public function execute(string $dispatchId): string
    {
        $d = DB::transaction(function () use ($dispatchId) {
            $d = DB::table('campaign_dispatches')->where('tenant_id', $this->context->requireTenant())->where('id', $dispatchId)->lockForUpdate()->first();
            if ($d === null || ! in_array($d->status, ['queued', 'running'], true)) {
                return null;
            }
            DB::table('campaign_dispatches')->where('id', $d->id)->update(['status' => 'running', 'started_at' => $d->started_at ?? now(), 'updated_at' => now()]);

            return $d;
        });
        if ($d === null) {
            return 'skipped';
        }
        $campaign = $this->campaigns->find($d->campaign_id);
        $actor = Actor::job(ExecuteCampaignDispatch::class);

        try {
            $this->guard->assert($d, $campaign);
            $recipients = DB::table('test_recipients')->where('tenant_id', $d->tenant_id)->where('channel', $d->channel)->where('active', true)
                ->whereIn('id', (array) json_decode($d->recipients, true))->get();
            $queued = $d->channel === 'email' ? $this->emails($d, $campaign, $recipients, $actor) : $this->pushes($d, $recipients, $actor);
        } catch (DispatchBlocked $e) {
            $this->setStatus($d, 'blocked', $e->reason);
            $this->audit->record(AuditAction::DispatchBlocked, 'campaign_dispatch', $d->id, ['reason' => $e->reason, 'stage' => 'execute'], $actor);

            return 'blocked';
        } catch (Throwable $e) {
            $this->setStatus($d, 'failed', mb_substr(class_basename($e), 0, 64));
            report($e);

            return 'failed';
        }

        DB::table('campaign_dispatches')->where('tenant_id', $d->tenant_id)->where('id', $d->id)
            ->update(['status' => 'completed', 'completed_at' => now(), 'counters' => json_encode(['handed_over' => $queued]), 'updated_at' => now()]);

        return 'completed';
    }

    public function find(string $dispatchId): object
    {
        return DB::table('campaign_dispatches')->where('tenant_id', $this->context->requireTenant())->where('id', $dispatchId)->first()
            ?? throw new DispatchBlocked('NOT_FOUND', 'Unknown dispatch.');
    }

    /**
     * @param  Collection<int, \stdClass>  $recipients
     */
    private function emails(object $d, object $campaign, $recipients, Actor $actor): int
    {
        $version = $this->templates->version((string) $campaign->template_version_id);
        $rendered = $this->templates->render($version, EmailTemplates::sampleValues($campaign->name));
        $prefix = (string) (json_decode($d->content, true)['subject_prefix'] ?? '[TEST] ');
        $n = 0;
        foreach ($recipients as $r) {
            $id = $this->email->queue(new EmailDraft($campaign->integration_id, $campaign->sender_identity_id, EmailMessageType::Test, (string) $r->provider_recipient_id,
                mb_substr($prefix.$rendered['subject'], 0, 200), $rendered['text'], $rendered['html'], idempotencyKey: "dispatch:{$d->id}:{$r->id}",
                campaignId: $campaign->id, snapshotId: $campaign->segment_snapshot_id, templateVersionId: $campaign->template_version_id, tags: ['dispatch' => $d->id]), $actor);
            DB::table('email_messages')->where('tenant_id', $d->tenant_id)->where('id', $id)->whereNull('dispatch_id')->update(['dispatch_id' => $d->id]);
            $this->audit->record(AuditAction::TestEmailQueued, 'campaign_dispatch', $d->id, ['message_id' => $id], $actor);
            $n++;
        }

        return $n;
    }

    /**
     * @param  Collection<int, \stdClass>  $recipients
     */
    private function pushes(object $d, $recipients, Actor $actor): int
    {
        $integration = TenantIntegration::query()->where('provider', 'onesignal')->where('capability', 'push')->firstOrFail();
        $content = (array) json_decode($d->content, true);
        $n = 0;
        foreach ($recipients as $r) {
            $subscription = (string) $r->provider_recipient_id;
            $done = DB::table('push_send_requests')->where('tenant_id', $d->tenant_id)->where('dispatch_id', $d->id)
                ->whereRaw('recipients @> ?::jsonb', [json_encode([$subscription])])->exists();
            if (! $done) {
                $id = $this->push->requestTestPush($integration, [$subscription], new PushMessage((string) $content['title'], (string) $content['body'],
                    campaignId: $d->campaign_id, metadata: ['dispatch' => $d->id]), $actor);
                DB::table('push_send_requests')->where('tenant_id', $d->tenant_id)->where('id', $id)->update(['dispatch_id' => $d->id]);
                $this->audit->record(AuditAction::TestPushQueued, 'campaign_dispatch', $d->id, ['push_request_id' => $id], $actor);
            }
            $n++;
        }

        return $n;
    }

    /**
     * @param  array{title?: string, body?: string}  $c
     * @return array{title: string, body: string}
     */
    private function pushContent(array $c): array
    {
        $message = new PushMessage(trim((string) ($c['title'] ?? '')), trim((string) ($c['body'] ?? '')));

        return ['title' => $message->title, 'body' => $message->body];
    }

    private function setStatus(object $d, string $status, ?string $reason): void
    {
        DB::table('campaign_dispatches')->where('tenant_id', $d->tenant_id)->where('id', $d->id)->update(['status' => $status, 'block_reason' => $reason, 'updated_at' => now()]);
    }
}
