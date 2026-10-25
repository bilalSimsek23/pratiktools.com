<?php

namespace Tests\Feature\Operations;

use App\Approvals\ApprovalError;
use App\Approvals\CampaignApprovals;
use App\Audit\Actor;
use App\Campaigns\Campaigns;
use App\Campaigns\CampaignStatus;
use App\Consent\ConsentStatus;
use App\Dispatch\CampaignDispatches;
use App\Dispatch\DispatchBlocked;
use App\Dispatch\DispatchGuard;
use App\Email\EmailMessageType;
use App\Email\Templates\EmailTemplates;
use App\Jobs\ExecuteCampaignDispatch;
use App\Jobs\SendEmail;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Operations\ExternalBlockers;
use App\Operations\IntegrationStatus;
use App\Operations\SendingModes;
use App\Segments\Segments;
use App\Tenancy\TenantRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithAutomation;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class ApprovalAndDispatchTest extends TestCase
{
    use InteractsWithAutomation, InteractsWithSes, InteractsWithTenants;

    private Tenant $tenant;

    private TenantIntegration $ses;

    private string $sender;

    private string $segment;

    private User $editor;

    private User $approver;

    private User $approver2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        Queue::fake();
        [$this->tenant] = $this->createTenant('shop');
        $this->ses = $this->connectSes($this->tenant);
        $this->sender = $this->senderIdentity($this->tenant, $this->ses);
        $this->iysSynced($this->tenant);
        $this->stats($this->tenant, $this->shopper($this->tenant, 'a@example.com', ConsentStatus::Granted), now()->subDays(5)->toImmutable());
        $this->segment = $this->inTenant($this->tenant, fn () => app(Segments::class)->create('Son 30 gün', 'dynamic',
            ['all' => [['field' => 'last_order_at', 'op' => 'within_last_days', 'value' => 30]]], Actor::system()));
        $this->editor = $this->addMember($this->tenant, TenantRole::Marketer);
        $this->approver = $this->addMember($this->tenant, TenantRole::Approver);
        $this->approver2 = $this->addMember($this->tenant, TenantRole::Approver);
        $this->emailTestRecipient($this->tenant, 'qa@example.com');
    }

    private function version(string $subject = 'Kasım {{ campaign.name }}'): string
    {
        return $this->inTenant($this->tenant, function () use ($subject) {
            $t = app(EmailTemplates::class);
            $id = $t->create('T'.Str::random(6), EmailMessageType::Marketing, Actor::system());
            $v = $t->addVersion($id, $subject, '<p>Merhaba</p><a href="{{ unsubscribe_url }}">Çık</a>', 'Merhaba {{ unsubscribe_url }}', Actor::system());
            $t->publish($v, Actor::system());

            return $v;
        });
    }

    private function readyCampaign(): string
    {
        return $this->inTenant($this->tenant, function () {
            $id = app(Campaigns::class)->create(['name' => 'Kasım '.Str::random(5), 'type' => 'newsletter', 'segment_id' => $this->segment, 'template_version_id' => $this->version(),
                'integration_id' => $this->ses->id, 'sender_identity_id' => $this->sender], Actor::user($this->editor->id));
            app(Campaigns::class)->transition($id, CampaignStatus::Ready, Actor::user($this->editor->id));

            return $id;
        });
    }

    private function campaign(string $id): object
    {
        return $this->inTenant($this->tenant, fn () => app(Campaigns::class)->find($id));
    }

    /**
     * @return array{review: string, approve: string}
     */
    private function requestApproval(string $campaign, ?User $by = null): array
    {
        return $this->inTenant($this->tenant, function () use ($campaign, $by) {
            app(CampaignApprovals::class)->request(app(Campaigns::class)->find($campaign), ($by ?? $this->editor)->id);

            return DB::table('campaign_approvals')->where('campaign_id', $campaign)->where('status', 'pending')->pluck('id', 'slot')->all();
        });
    }

    private function decide(string $approval, string $campaign, User $user, bool $approve = true, ?string $reason = null): ?string
    {
        try {
            $this->inTenant($this->tenant, fn () => app(CampaignApprovals::class)->decide($approval, app(Campaigns::class)->find($campaign), $user->id, $approve, $reason));
        } catch (ApprovalError $e) {
            return $e->reason;
        }

        return null;
    }

    private function recipientIds(): array
    {
        return $this->inTenant($this->tenant, fn () => DB::table('test_recipients')->where('channel', 'email')->pluck('id')->all());
    }

    private function dispatch(string $campaign, string $mode = 'test_only', ?array $recipients = null, bool $new = false): string
    {
        return $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->request($campaign, 'email', $mode, $recipients ?? $this->recipientIds(), $this->editor->id, newGeneration: $new));
    }

    private function blocked(callable $fn): ?string
    {
        try {
            $fn();
        } catch (DispatchBlocked $e) {
            return $e->reason;
        }

        return null;
    }

    private function approved(string $campaign): string
    {
        $slots = $this->requestApproval($campaign);
        $this->assertNull($this->decide($slots['approve'], $campaign, $this->approver));

        return $slots['review'];
    }

    public function test_creator_and_requester_cannot_approve_and_two_distinct_people_are_needed(): void
    {
        $campaign = $this->readyCampaign();
        $slots = $this->requestApproval($campaign);

        $this->assertSame('SELF_APPROVAL', $this->decide($slots['approve'], $campaign, $this->editor));
        $this->assertSame('REASON_REQUIRED', $this->decide($slots['approve'], $campaign, $this->approver, approve: false));
        $this->assertNull($this->decide($slots['approve'], $campaign, $this->approver));
        $this->assertSame('ALREADY_DECIDED', $this->decide($slots['approve'], $campaign, $this->approver2));
        $this->assertSame('SAME_PERSON', $this->decide($slots['review'], $campaign, $this->approver));
        $this->assertNull($this->decide($slots['review'], $campaign, $this->approver2));
        $this->assertCount(2, $this->inTenant($this->tenant, fn () => app(CampaignApprovals::class)->valid($this->campaign($campaign))));

        // The database refuses a decider equal to the requester, whatever the code path.
        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => DB::table('campaign_approvals')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'campaign_id' => $campaign,
            'fingerprint' => str_repeat('a', 64), 'slot' => 'approve', 'requested_by' => $this->editor->id, 'decided_by' => $this->editor->id, 'status' => 'approved',
            'requested_at' => now(), 'expires_at' => now()->addDay()]));
    }

    public function test_requester_who_is_not_the_creator_still_cannot_approve(): void
    {
        $campaign = $this->readyCampaign();
        $admin = $this->addMember($this->tenant, TenantRole::Admin);
        $slots = $this->requestApproval($campaign, $admin);

        $this->assertSame('SELF_APPROVAL', $this->decide($slots['approve'], $campaign, $admin));
        $this->assertSame('SELF_APPROVAL', $this->decide($slots['review'], $campaign, $this->editor), 'The creator never approves.');
    }

    public function test_approval_records_are_immutable(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);
        $id = $this->inTenant($this->tenant, fn () => DB::table('campaign_approvals')->where('status', 'approved')->value('id'));

        foreach ([['decided_by' => $this->approver2->id], ['fingerprint' => str_repeat('b', 64)], ['status' => 'pending']] as $change) {
            try {
                $this->inTenant($this->tenant, fn () => DB::table('campaign_approvals')->where('id', $id)->update($change));
                $this->fail('Approval row changed: '.json_encode($change));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_content_audience_and_sender_changes_each_invalidate_approvals(): void
    {
        $otherSegment = $this->inTenant($this->tenant, fn () => app(Segments::class)->create('Hepsi', 'dynamic', ['all' => [['field' => 'order_count', 'op' => 'gte', 'value' => 0]]], Actor::system()));
        $otherSender = $this->inTenant($this->tenant, function () {
            $id = (string) Str::uuid7();
            DB::table('email_sender_identities')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'integration_id' => $this->ses->id, 'provider' => 'amazon_ses', 'domain' => 'example.com',
                'from_email' => 'news@example.com', 'provider_identity_id' => 'example.com', 'verification_status' => 'verified', 'dkim_status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        foreach (['content' => ['template_version_id' => $this->version('Yeni konu')], 'audience' => ['segment_id' => $otherSegment], 'sender' => ['sender_identity_id' => $otherSender]] as $what => $change) {
            $campaign = $this->readyCampaign();
            $this->approved($campaign);
            $fingerprint = CampaignApprovals::fingerprint($this->campaign($campaign));

            $this->inTenant($this->tenant, function () use ($campaign, $change) {
                app(Campaigns::class)->reopen($campaign, Actor::user($this->editor->id));
                app(Campaigns::class)->update($campaign, $change, Actor::user($this->editor->id));
                app(Campaigns::class)->transition($campaign, CampaignStatus::Ready, Actor::user($this->editor->id));
            });

            $this->assertNotSame($fingerprint, CampaignApprovals::fingerprint($this->campaign($campaign)), $what);
            $this->assertCount(0, $this->inTenant($this->tenant, fn () => app(CampaignApprovals::class)->valid($this->campaign($campaign))), $what);
            $this->assertSame(['invalidated'], $this->inTenant($this->tenant, fn () => DB::table('campaign_approvals')->where('campaign_id', $campaign)->distinct()->pluck('status')->all()), $what);
        }
    }

    public function test_frozen_campaign_cannot_change_without_reopen(): void
    {
        $campaign = $this->readyCampaign();
        $snapshot = $this->campaign($campaign)->segment_snapshot_id;

        $this->expectException(QueryException::class);
        try {
            $this->inTenant($this->tenant, fn () => DB::table('campaigns')->where('id', $campaign)->update(['template_version_id' => $this->version('x')]));
        } finally {
            $this->assertSame($snapshot, $this->campaign($campaign)->segment_snapshot_id);
        }
    }

    public function test_test_only_dispatch_is_allowlist_only_and_live_is_hard_blocked(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);

        $this->assertSame('RECIPIENT_NOT_ALLOWLISTED', $this->blocked(fn () => $this->dispatch($campaign, recipients: [(string) Str::uuid7()])));
        $this->assertSame('RECIPIENT_COUNT', $this->blocked(fn () => $this->dispatch($campaign, recipients: ['someone@example.com'])));
        $this->assertSame('LIVE_DISABLED', $this->blocked(fn () => $this->dispatch($campaign, mode: 'live')));
        $this->assertFalse(DispatchGuard::LIVE_AVAILABLE);

        $id = $this->dispatch($campaign);
        $d = $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->find($id));
        $this->assertSame('approved', $d->status);
        $this->assertSame(0, json_decode($d->plan, true)['provider_calls_made']);
        $this->assertSame([], $this->sesRequests);

        // A row cannot be switched to live or to other recipients afterwards.
        foreach ([['mode' => 'live'], ['recipients' => json_encode([(string) Str::uuid7()])]] as $change) {
            try {
                $this->inTenant($this->tenant, fn () => DB::table('campaign_dispatches')->where('id', $id)->update($change));
                $this->fail('Dispatch changed: '.json_encode($change));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dispatch_needs_approval_and_test_only_mode_before_queueing(): void
    {
        $campaign = $this->readyCampaign();
        $id = $this->dispatch($campaign);
        $this->assertSame('awaiting_approval', $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->find($id))->status);
        $this->assertSame('INVALID_STATE', $this->blocked(fn () => $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->queue($id, $this->editor->id))));

        $this->approved($campaign);
        $this->assertSame('approved', $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->refresh($id))->status);

        // Sending mode off: the plan exists, queueing is refused before any provider.
        $this->assertSame('SENDING_MODE_NOT_TEST_ONLY', $this->blocked(fn () => $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->queue($id, $this->editor->id))));
        Queue::assertNotPushed(ExecuteCampaignDispatch::class);

        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->refresh($id));
        $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->queue($id, $this->editor->id));
        Queue::assertPushed(ExecuteCampaignDispatch::class, 1);

        $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->execute($id));
        $this->assertSame('completed', $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->find($id))->status);
        $messages = $this->inTenant($this->tenant, fn () => DB::table('email_messages')->where('dispatch_id', $id)->get(['message_type', 'subject']));
        $this->assertCount(1, $messages);
        $this->assertSame('test', $messages[0]->message_type);
        $this->assertStringStartsWith('[TEST] ', $messages[0]->subject);
        Queue::assertPushed(SendEmail::class, 1);
        $this->assertSame([], $this->sesRequests, 'Execution only queues; the provider is reached by the send job.');

        // A redelivered job does nothing, and a completed request is final.
        $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->execute($id));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->where('dispatch_id', $id)->count()));
        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => DB::table('campaign_dispatches')->where('id', $id)->update(['status' => 'queued']));
    }

    public function test_duplicate_requests_are_idempotent_and_new_dispatch_needs_explicit_action(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);
        $first = $this->dispatch($campaign);

        $this->assertSame($first, $this->dispatch($campaign));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('campaign_dispatches')->where('campaign_id', $campaign)->count()));
        $second = $this->dispatch($campaign, new: true);
        $this->assertNotSame($first, $second);
        $this->assertSame([1, 2], $this->inTenant($this->tenant, fn () => DB::table('campaign_dispatches')->where('campaign_id', $campaign)->orderBy('generation')->pluck('generation')->all()));
    }

    public function test_cancelled_dispatch_cannot_resume_and_reopen_cancels_open_dispatches(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);
        $id = $this->dispatch($campaign);
        $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->cancel($id, $this->editor->id));

        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->assertSame('INVALID_STATE', $this->blocked(fn () => $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->queue($id, $this->editor->id))));
        $this->expectException(QueryException::class);
        $this->inTenant($this->tenant, fn () => DB::table('campaign_dispatches')->where('id', $id)->update(['status' => 'queued']));
    }

    public function test_reopen_cancels_open_dispatches_with_an_audit_event(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);
        $id = $this->dispatch($campaign);
        $this->inTenant($this->tenant, fn () => app(Campaigns::class)->reopen($campaign, Actor::user($this->editor->id)));

        $this->assertSame('cancelled', $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->find($id))->status);
        $this->assertTrue($this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'dispatch.cancelled')->where('resource_id', $id)->exists()));
    }

    public function test_guard_refuses_a_campaign_that_drifted_from_the_approved_dispatch(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);
        $id = $this->dispatch($campaign);
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $guard = app(DispatchGuard::class);
        $d = $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->find($id));

        // Rows cannot drift (triggers); the guard re-checks anyway before any provider.
        $drifted = clone $this->campaign($campaign);
        $drifted->template_version_id = $this->version('Değişti');
        $this->assertSame('CAMPAIGN_CHANGED', $this->blocked(fn () => $this->inTenant($this->tenant, fn () => $guard->assert($d, $drifted))));
        $this->assertNull($this->blocked(fn () => $this->inTenant($this->tenant, fn () => $guard->assert($d, $this->campaign($campaign)))));
    }

    public function test_live_forced_in_config_never_reaches_the_network(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);
        $this->emailModes($this->tenant, 'live', 'live');
        $this->inTenant($this->tenant, fn () => $this->ses->forceFill(['settings' => ['account' => ['production_access' => true] + $this->ses->settings['account']] + $this->ses->settings])->save());
        config(['dispatch.required_approvals' => ['test_only' => 0, 'live' => 0]]);

        $this->assertSame('LIVE_DISABLED', $this->blocked(fn () => $this->dispatch($campaign, mode: 'live')));
        // Even a live row in memory is refused by the guard used at queue and execution time.
        $live = clone $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->find($this->dispatch($campaign)));
        $live->mode = 'live';
        $this->assertSame('LIVE_DISABLED', $this->blocked(fn () => $this->inTenant($this->tenant, fn () => app(DispatchGuard::class)->assert($live, $this->campaign($campaign)))));
        // With live everywhere, a test_only dispatch is not sent either: it needs the test_only mode.
        $this->assertSame('SENDING_MODE_NOT_TEST_ONLY', $this->blocked(fn () => $this->inTenant($this->tenant, fn () => app(CampaignDispatches::class)->queue($live->id, $this->editor->id))));

        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count()));
        Queue::assertNotPushed(SendEmail::class);
        $this->assertSame([], $this->sesRequests);
    }

    public function test_sending_mode_can_only_be_off_or_test_only_and_attempts_are_audited(): void
    {
        $this->inTenant($this->tenant, fn () => app(SendingModes::class)->set('internal', Actor::system()));
        foreach (['live', 'pilot', 'anything'] as $mode) {
            try {
                $this->inTenant($this->tenant, fn () => app(SendingModes::class)->set($mode, Actor::system()));
                $this->fail("{$mode} accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Live sending is disabled until external readiness requirements are satisfied.', $e->getMessage());
            }
        }
        $this->assertSame('internal', $this->inTenant($this->tenant, fn () => DB::table('tenant_settings')->value('sending_mode')));
        $this->assertSame(3, $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'tenant.sending_mode_change_refused')->count()));
    }

    public function test_external_blockers_are_derived_from_real_state(): void
    {
        config(['email.unsubscribe.key' => null]);
        $rows = $this->inTenant($this->tenant, fn () => collect(app(ExternalBlockers::class)->all(app(IntegrationStatus::class)->all()))->keyBy('blocker'));

        $this->assertSame('BLOCKED', $rows['SES_PRODUCTION_ACCESS']['status']);
        $this->assertSame('BLOCKED', $rows['EMAIL_UNSUBSCRIBE_KEY']['status']);
        $this->assertSame('Add EMAIL_UNSUBSCRIBE_KEY as Laravel Cloud Secret.', $rows['EMAIL_UNSUBSCRIBE_KEY']['action']);
        $this->assertSame('PASS', $rows['IYS_API_ACCESS']['status'], 'This tenant has İYS synced.');
        $this->assertSame('BLOCKED', $rows['ONESIGNAL_EXTERNAL_ID']['status']);
        $this->assertStringContainsString('External ID coverage: 0 / ', $rows['ONESIGNAL_EXTERNAL_ID']['detail']);
        $this->assertSame('PASS', $rows['SES_VAULT_CONNECTION']['status']);
    }

    public function test_integration_status_never_contains_secrets(): void
    {
        $status = $this->inTenant($this->tenant, fn () => app(IntegrationStatus::class)->all());
        $json = json_encode($status);
        $encrypted = $this->inTenant($this->tenant, fn () => DB::table('tenant_integrations')->where('id', $this->ses->id)->value('encrypted_secret'));

        $this->assertStringNotContainsString(self::SES_SECRET, $json);
        $this->assertStringNotContainsString(self::SES_ACCESS_KEY, $json);
        $this->assertStringNotContainsString((string) $encrypted, $json);
        $this->assertStringNotContainsString((string) config('email.unsubscribe.key'), $json);
        $this->assertTrue($status['amazon_ses']['vault_secret']);
    }

    public function test_approvals_and_dispatches_are_tenant_isolated(): void
    {
        $campaign = $this->readyCampaign();
        $this->approved($campaign);
        $id = $this->dispatch($campaign);
        [$other] = $this->createTenant('other');

        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('campaign_approvals')->count() + DB::table('campaign_dispatches')->count() + DB::table('tenant_notifications')->count()));
        $this->assertSame('NOT_FOUND', $this->blocked(fn () => $this->inTenant($other, fn () => app(CampaignDispatches::class)->find($id))));
    }
}
