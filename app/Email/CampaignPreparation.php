<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Consent\ConsentChannel;
use App\Consent\SendEligibility;
use App\Email\Data\EmailDraft;
use App\Email\Templates\EmailTemplates;
use App\Email\Templates\TemplateError;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campaign → audience snapshot → message generation → consent filtering
 * → queue → provider. Nothing here runs on its own: no scheduler, no
 * automation.
 *
 * - snapshot(): freezes the selected recipients; membership never changes
 *   afterwards, so the send cannot drift from what was approved.
 * - generate(): renders one message per eligible member with a stable
 *   idempotency key (campaign:snapshot:recipient), so running it twice
 *   queues nothing new. Ineligible members are counted by reason; the
 *   guard checks every message again at send time.
 */
final class CampaignPreparation
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SendEligibility $eligibility,
        private readonly EmailTemplates $templates,
        private readonly EmailDelivery $delivery,
        private readonly UnsubscribeTokens $unsubscribe,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $recipientIds  e-mail consent recipients
     * @param  array<string, mixed>  $criteria  how they were chosen (stored as is)
     */
    public function snapshot(array $recipientIds, ?string $campaignId, array $criteria, Actor $actor): string
    {
        $tenantId = $this->context->requireTenant();
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $id, $recipientIds, $campaignId, $criteria, $actor) {
            DB::table('email_audience_snapshots')->insert(['id' => $id, 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'criteria' => json_encode((object) $criteria),
                'created_by' => mb_substr($actor->type.($actor->id !== null ? ':'.$actor->id : ''), 0, 64), 'created_at' => now()]);
            foreach (array_chunk(array_values(array_unique($recipientIds)), 1000) as $chunk) {
                DB::statement(
                    'INSERT INTO email_audience_members (tenant_id, snapshot_id, recipient_id, customer_id)
                     SELECT r.tenant_id, ?, r.id, (SELECT min(rc.customer_id::text)::uuid FROM recipient_customers rc WHERE rc.tenant_id = r.tenant_id AND rc.recipient_id = r.id)
                     FROM consent_recipients r WHERE r.tenant_id = ? AND r.address_type = \'email\' AND r.id = ANY(?::uuid[]) ON CONFLICT DO NOTHING',
                    [$id, $tenantId, '{'.implode(',', $chunk).'}'],
                );
            }
            $count = DB::table('email_audience_members')->where('tenant_id', $tenantId)->where('snapshot_id', $id)->count();
            DB::table('email_audience_snapshots')->where('tenant_id', $tenantId)->where('id', $id)->update(['member_count' => $count]);
            $this->audit->record(AuditAction::EmailAudienceSnapshotCreated, 'email_audience_snapshot', $id, ['members' => $count, 'campaign_id' => $campaignId], $actor);
        });

        return $id;
    }

    /**
     * @param  array<string, scalar|null>  $shared  campaign-wide variables (product.*, coupon.*)
     * @return array{queued: int, duplicates: int, excluded: array<string, int>}
     */
    public function generate(string $snapshotId, string $templateVersionId, TenantIntegration $integration, string $senderIdentityId, array $shared, Actor $actor): array
    {
        $tenantId = $this->context->requireTenant();
        $snapshot = DB::table('email_audience_snapshots')->where('tenant_id', $tenantId)->where('id', $snapshotId)->first()
            ?? throw new InvalidEmailMessage('UNKNOWN_SNAPSHOT', 'Unknown audience snapshot.');
        $version = $this->templates->version($templateVersionId);
        if ($version->message_type !== EmailMessageType::Marketing->value || $version->status !== 'published') {
            throw new TemplateError('INVALID_TEMPLATE', 'Campaigns use published marketing templates.');
        }
        $store = $this->store($tenantId);
        $result = ['queued' => 0, 'duplicates' => 0, 'excluded' => []];

        DB::table('email_audience_members as m')->join('consent_recipients as r', fn ($j) => $j->on('r.id', '=', 'm.recipient_id')->on('r.tenant_id', '=', 'm.tenant_id'))
            ->leftJoin('customers as c', fn ($j) => $j->on('c.id', '=', 'm.customer_id')->on('c.tenant_id', '=', 'm.tenant_id'))
            ->where('m.tenant_id', $tenantId)->where('m.snapshot_id', $snapshotId)->orderBy('m.recipient_id')
            ->select(['m.recipient_id', 'm.customer_id', 'r.address', 'c.first_name', 'c.last_name'])
            ->chunk(500, function ($members) use (&$result, $snapshot, $version, $integration, $senderIdentityId, $shared, $store, $actor, $tenantId) {
                foreach ($members as $m) {
                    $decision = $this->eligibility->forRecipient($m->recipient_id, ConsentChannel::Email);
                    if (! $decision->allowed()) {
                        $result['excluded'][$decision->reason->value] = ($result['excluded'][$decision->reason->value] ?? 0) + 1;

                        continue;
                    }
                    $url = $this->unsubscribe->url($this->unsubscribe->issue($tenantId, $m->recipient_id));
                    try {
                        $content = $this->templates->render($version, $shared + $store + [
                            'customer.first_name' => $m->first_name, 'customer.last_name' => $m->last_name,
                            'customer.full_name' => trim(($m->first_name ?? '').' '.($m->last_name ?? '')) ?: null,
                            'unsubscribe_url' => $url, 'current_year' => (string) now()->year,
                        ]);
                    } catch (TemplateError $e) {
                        $result['excluded'][$e->reason] = ($result['excluded'][$e->reason] ?? 0) + 1;

                        continue;
                    }

                    $key = 'campaign:'.$snapshot->id.':'.$m->recipient_id;
                    $exists = DB::table('email_messages')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->exists();
                    $this->delivery->queue(new EmailDraft($integration->id, $senderIdentityId, EmailMessageType::Marketing, $m->address, $content['subject'], $content['text'], $content['html'],
                        idempotencyKey: $key, customerId: $m->customer_id, campaignId: $snapshot->campaign_id, snapshotId: $snapshot->id, templateVersionId: $version->id,
                        headers: $this->unsubscribe->headers($url), tags: array_filter(['campaign' => $snapshot->campaign_id])), $actor);
                    $exists ? $result['duplicates']++ : $result['queued']++;
                }
            });

        return $result;
    }

    /**
     * @return array<string, string|null>
     */
    private function store(string $tenantId): array
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name']);
        $domain = DB::table('tenant_settings')->where('tenant_id', $tenantId)->value('primary_domain');

        return ['store.name' => $tenant?->name, 'store.url' => is_string($domain) && $domain !== '' ? 'https://'.$domain : null];
    }
}
