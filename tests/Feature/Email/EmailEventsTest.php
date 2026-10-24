<?php

namespace Tests\Feature\Email;

use App\Audit\Actor;
use App\Email\Data\EmailDraft;
use App\Email\EmailDelivery;
use App\Email\EmailMessageType;
use App\Email\UnsubscribeTokens;
use App\Jobs\SendEmail;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class EmailEventsTest extends TestCase
{
    use InteractsWithSes, InteractsWithTenants;

    private const TO = 'qa@example.com';

    private Tenant $tenant;

    private TenantIntegration $ses;

    private string $messageId;

    private string $sesMessageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        [$this->tenant] = $this->createTenant('shop');
        $this->ses = $this->connectSes($this->tenant);
        $sender = $this->senderIdentity($this->tenant, $this->ses);
        $this->emailTestRecipient($this->tenant, self::TO);
        $this->emailModes($this->tenant, 'test_only', 'internal');
        Queue::fake([SendEmail::class]);

        $this->messageId = $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->queue(
            new EmailDraft($this->ses->id, $sender, EmailMessageType::Test, self::TO, 'Merhaba', 'Metin'), Actor::system()));
        $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->send($this->messageId, Actor::system()));
        $this->sesMessageId = $this->inTenant($this->tenant, fn () => (string) DB::table('email_messages')->value('provider_message_id'));
    }

    private function bounce(string $type, string $subtype, ?string $feedbackId = null): array
    {
        return self::sesEvent('Bounce', $this->sesMessageId, self::TO, ['bounce' => [
            'bounceType' => $type, 'bounceSubType' => $subtype, 'feedbackId' => $feedbackId ?? 'fb-'.uniqid(), 'timestamp' => now()->toIso8601String(),
            'bouncedRecipients' => [['emailAddress' => self::TO, 'status' => '5.1.1', 'diagnosticCode' => 'smtp; 550 5.1.1 <qa@example.com>: user unknown']],
        ]], $this->messageId);
    }

    private function suppressions(): array
    {
        return $this->inTenant($this->tenant, fn () => DB::table('suppressions')->whereNull('lifted_at')->pluck('source')->all());
    }

    private function events(): int
    {
        return $this->inTenant($this->tenant, fn () => DB::table('email_events')->count());
    }

    public function test_a_signed_hard_bounce_is_stored_once_and_suppresses(): void
    {
        $this->postSns($this->tenant, $this->snsNotification($this->bounce('Permanent', 'NoEmail')))->assertStatus(202);

        $event = $this->inTenant($this->tenant, fn () => DB::table('email_events')->first());
        $this->assertSame('EMAIL_BOUNCED', $event->event_type);
        $this->assertSame('hard', $event->bounce_class);
        $this->assertSame($this->messageId, $event->message_id);
        $this->assertSame(['SES_BOUNCE'], $this->suppressions());
        $this->assertSame('bounced', $this->inTenant($this->tenant, fn () => DB::table('email_messages')->value('delivery_status')));
        // Only codes are kept: no subject, no diagnostic text, no address.
        $stored = json_encode($event);
        foreach (['Gizli kampanya konusu', 'user unknown', self::TO] as $needle) {
            $this->assertStringNotContainsString($needle, $stored);
        }
        $this->assertStringContainsString('5.1.1', $stored);

        // The suppression now stops any further mail to the address.
        $next = $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->queue(new EmailDraft($this->ses->id,
            (string) DB::table('email_sender_identities')->value('id'), EmailMessageType::Test, self::TO, 'Tekrar', 'Metin'), Actor::system()));
        $this->assertSame('blocked', $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->send($next, Actor::system())));
        $this->assertCount(1, $this->sesRequestsTo('/v2/email/outbound-emails'));
    }

    public function test_a_complaint_suppresses_marketing(): void
    {
        $this->postSns($this->tenant, $this->snsNotification(self::sesEvent('Complaint', $this->sesMessageId, self::TO, ['complaint' => [
            'complainedRecipients' => [['emailAddress' => self::TO]], 'feedbackId' => 'c-1', 'complaintFeedbackType' => 'abuse', 'timestamp' => now()->toIso8601String(),
        ]], $this->messageId)))->assertStatus(202);

        $this->assertSame(['SES_COMPLAINT'], $this->suppressions());
        $this->assertSame('complained', $this->inTenant($this->tenant, fn () => DB::table('email_messages')->value('delivery_status')));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => (int) DB::table('email_usage_daily')->value('complaints')));
    }

    public function test_soft_bounces_suppress_only_after_the_threshold(): void
    {
        $this->postSns($this->tenant, $this->snsNotification($this->bounce('Transient', 'MailboxFull')))->assertStatus(202);
        $this->postSns($this->tenant, $this->snsNotification($this->bounce('Undetermined', 'Undetermined')))->assertStatus(202);
        $this->postSns($this->tenant, $this->snsNotification($this->bounce('Transient', 'General')))->assertStatus(202);
        $this->assertSame([], $this->suppressions());

        $this->postSns($this->tenant, $this->snsNotification($this->bounce('Transient', 'MailboxFull')))->assertStatus(202);
        $this->assertSame(['SOFT_BOUNCE'], $this->suppressions());
        $this->assertSame(4, $this->events());
    }

    public function test_duplicate_deliveries_give_one_canonical_event(): void
    {
        $envelope = $this->snsNotification($this->bounce('Permanent', 'General', 'fb-same'));
        $this->postSns($this->tenant, $envelope)->assertStatus(202);
        // SNS retry: same MessageId.
        $this->postSns($this->tenant, $envelope)->assertOk()->assertJson(['status' => 'duplicate']);
        // Re-published copy of the same SES event under a new SNS MessageId.
        $this->postSns($this->tenant, $this->snsNotification($this->bounce('Permanent', 'General', 'fb-same')))->assertStatus(202);

        $this->assertSame(1, $this->events());
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => DB::table('webhook_inbox')->count()));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => (int) DB::table('email_usage_daily')->value('emails_bounced')));
        $this->assertSame(['SES_BOUNCE'], $this->suppressions());
    }

    public function test_unsigned_forged_or_foreign_deliveries_are_refused(): void
    {
        $record = $this->bounce('Permanent', 'General');
        $valid = $this->snsNotification($record);

        $tampered = $valid;
        $tampered['Message'] = json_encode($this->bounce('Permanent', 'General') + ['eventType' => 'Complaint']);
        $this->postSns($this->tenant, $tampered)->assertStatus(401);

        $raw = $record; // raw message delivery: no SNS envelope, no signature
        $this->postSns($this->tenant, $raw)->assertStatus(401);

        $this->postSns($this->tenant, $this->snsNotification($record, ['TopicArn' => 'arn:aws:sns:eu-central-1:999999999999:attacker']))->assertStatus(401);
        $this->postSns($this->tenant, $this->snsNotification($record, ['SigningCertURL' => 'https://attacker.example.com/cert.pem']))->assertStatus(401);
        $this->postSns($this->tenant, $this->snsNotification($record, ['SigningCertURL' => 'https://sns.eu-central-1.amazonaws.com.attacker.net/x.pem']))->assertStatus(401);
        $this->postSns($this->tenant, $this->snsNotification($record, ['SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/x.pem']))->assertStatus(401);
        $this->postSns($this->tenant, $this->snsNotification($record, ['Timestamp' => now()->subDays(3)->utc()->format('Y-m-d\TH:i:s.v\Z')]))->assertStatus(401);

        // Another tenant's URL with this tenant's topic is refused too.
        [$other] = $this->createTenant('other');
        $this->postSns($other, $valid)->assertStatus(401);

        $this->assertSame(0, $this->events());
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('webhook_inbox')->count()));
        $this->postSns($this->tenant, $valid)->assertStatus(202);
        $this->assertSame(1, $this->events());
    }

    public function test_a_subscription_confirmation_is_recorded_not_confirmed(): void
    {
        $confirmation = $this->signSns([
            'Type' => 'SubscriptionConfirmation', 'MessageId' => 'm-sub-1', 'Token' => 'tok', 'TopicArn' => self::SNS_TOPIC,
            'Message' => 'You have chosen to subscribe to the topic.', 'Timestamp' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'SubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=x&Token=tok',
            'SignatureVersion' => '1', 'SigningCertURL' => self::SNS_CERT_URL,
        ]);
        $this->sesRequests = [];
        $this->postSns($this->tenant, $confirmation)->assertStatus(202);

        $this->assertSame('pending_confirmation', $this->inTenant($this->tenant, fn () => $this->ses->fresh()->settings['sns_subscription']['state']));
        $this->assertSame([], array_values(array_filter($this->sesRequests, fn ($r) => str_contains($r->url(), 'ConfirmSubscription'))));
    }

    public function test_a_signed_unsubscribe_link_applies_once(): void
    {
        $recipient = $this->inTenant($this->tenant, fn () => (string) DB::table('consent_recipients')->where('address', self::TO)->value('id'));
        $token = app(UnsubscribeTokens::class)->issue($this->tenant->id, $recipient);

        // GET only confirms: link scanners cannot unsubscribe anyone.
        $this->get('/u/'.$token)->assertOk()->assertSee('Abonelikten çık');
        $this->assertSame([], $this->suppressions());

        $this->post('/u/'.$token)->assertOk()->assertSee('Abonelikten çıkıldı');
        $this->call('POST', '/u/'.$token, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'List-Unsubscribe=One-Click')->assertOk();

        $this->assertSame(['UNSUBSCRIBE'], $this->suppressions());
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('email_unsubscribes')->count()));
        $consent = $this->inTenant($this->tenant, fn () => DB::table('consents')->where('recipient_id', $recipient)->where('source_provider', 'PRATIKTOOLS')->first());
        $this->assertSame('REVOKED', $consent->status);
        $intent = $this->inTenant($this->tenant, fn () => DB::table('consent_write_intents')->first());
        $this->assertSame(['IYS', 'RET', 'pending_write_disabled'], [$intent->target, $intent->status, $intent->state]);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'email.unsubscribed')->count()));
        // Nothing went to İYS.
        $this->assertSame([], array_values(array_filter($this->sesRequests, fn ($r) => str_contains($r->url(), 'iys'))));

        $other = app(UnsubscribeTokens::class)->issue($this->tenant->id, $recipient);
        $this->call('POST', '/u/'.$other, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'List-Unsubscribe=One-Click')->assertOk();
        $this->assertEqualsCanonicalizing(['link', 'one_click'], $this->inTenant($this->tenant, fn () => DB::table('email_unsubscribes')->pluck('method')->all()));
        $this->assertSame(['UNSUBSCRIBE'], $this->suppressions());
    }

    public function test_invalid_tampered_foreign_or_expired_tokens_do_nothing(): void
    {
        $tokens = app(UnsubscribeTokens::class);
        $recipient = $this->inTenant($this->tenant, fn () => (string) DB::table('consent_recipients')->where('address', self::TO)->value('id'));
        $valid = $tokens->issue($this->tenant->id, $recipient);
        [$payload, $mac] = explode('.', $valid);

        $forged = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 't' => $this->tenant->id, 'r' => '00000000-0000-4000-8000-000000000000', 'i' => time()])), '+/', '-_'), '=').'.'.$mac;
        $this->post('/u/'.$forged)->assertStatus(410);
        $this->post('/u/'.$payload.'.'.str_repeat('A', 43))->assertStatus(410);
        $this->get('/u/not-a-token')->assertNotFound();

        config(['email.unsubscribe.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
        $this->post('/u/'.$valid)->assertStatus(410);
        config(['email.unsubscribe.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->travel(181)->days();
        $this->post('/u/'.$valid)->assertStatus(410);
        $this->travelBack();

        $this->assertSame([], $this->suppressions());
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_unsubscribes')->count()));
    }
}
