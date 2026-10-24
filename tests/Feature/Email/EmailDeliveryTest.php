<?php

namespace Tests\Feature\Email;

use App\Audit\Actor;
use App\Connectors\Ses\SesClientFactory;
use App\Connectors\Ses\SesEmailProvider;
use App\Consent\ConsentChannel;
use App\Consent\ConsentStatus;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Email\Contracts\EmailProvider;
use App\Email\Contracts\EmailProviderFactory;
use App\Email\Data\ConnectionResult;
use App\Email\Data\EmailDraft;
use App\Email\Data\ProviderSendResult;
use App\Email\EmailCapabilities;
use App\Email\EmailCapability;
use App\Email\EmailDelivery;
use App\Email\EmailMessageType;
use App\Email\EmailProviderResolver;
use App\Email\EmailSendAuthorization;
use App\Email\EmailSendBlocked;
use App\Email\EmailSendGuard;
use App\Email\InvalidEmailMessage;
use App\Email\OutboundEmail;
use App\Jobs\SendEmail;
use App\Logging\Redactor;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class EmailDeliveryTest extends TestCase
{
    use InteractsWithSes, InteractsWithTenants;

    private const TESTER = 'qa@example.com';

    private const CUSTOMER = 'musteri@example.org';

    private Tenant $tenant;

    private TenantIntegration $ses;

    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        [$this->tenant] = $this->createTenant('shop');
        $this->ses = $this->connectSes($this->tenant);
        $this->sender = $this->senderIdentity($this->tenant, $this->ses);
        $this->emailTestRecipient($this->tenant, self::TESTER);
        Queue::fake([SendEmail::class]);
    }

    private function draft(EmailMessageType $type, string $to, array $overrides = []): EmailDraft
    {
        $args = $overrides + ['subject' => 'Merhaba', 'text' => 'Metin gövdesi', 'html' => '<p>HTML gövdesi</p>', 'headers' => [], 'idempotencyKey' => null];

        return new EmailDraft($overrides['integrationId'] ?? $this->ses->id, $overrides['senderIdentityId'] ?? $this->sender, $type, $to, $args['subject'], $args['text'], $args['html'],
            idempotencyKey: $args['idempotencyKey'], headers: $args['headers']);
    }

    private function queue(EmailMessageType $type, string $to, array $overrides = []): string
    {
        return $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->queue($this->draft($type, $to, $overrides), Actor::system()));
    }

    private function send(string $id): string
    {
        return $this->inTenant($this->tenant, fn () => app(EmailDelivery::class)->send($id, Actor::system()));
    }

    private function message(string $id): object
    {
        return $this->inTenant($this->tenant, fn () => DB::table('email_messages')->where('id', $id)->first());
    }

    private function sends(): array
    {
        return $this->sesRequestsTo('/v2/email/outbound-emails');
    }

    public function test_resolver_selects_ses_and_builds_the_ses_provider(): void
    {
        $resolver = app(EmailProviderResolver::class);
        $primary = $this->inTenant($this->tenant, fn () => $resolver->primary());

        $this->assertSame($this->ses->id, $primary->id);
        $this->assertNull($this->inTenant($this->tenant, fn () => $resolver->secondary()));
        $this->assertInstanceOf(SesEmailProvider::class, $this->inTenant($this->tenant, fn () => $resolver->provider($primary, 'connection_test')));
        $caps = $this->inTenant($this->tenant, fn () => $resolver->capabilities($primary));
        $this->assertTrue($caps->has(EmailCapability::SendEmail) && $caps->has(EmailCapability::BounceEvents));
        // SES bulk sending needs SES templates: no multi-message raw send.
        $this->assertFalse($caps->has(EmailCapability::SendBatch));
        $this->assertFalse($caps->has(EmailCapability::OpenTracking));
    }

    public function test_tenant_isolation(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $id = $this->queue(EmailMessageType::Test, self::TESTER);
        [$other] = $this->createTenant('other');

        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('email_messages')->count()));
        $this->assertSame(0, $this->inTenant($other, fn () => DB::table('email_sender_identities')->count()));
        $this->assertSame('skipped', $this->inTenant($other, fn () => app(EmailDelivery::class)->send($id, Actor::system())));
        $this->assertNull($this->inTenant($other, fn () => TenantIntegration::query()->find($this->ses->id)));
        $this->assertSame([], $this->sends());
    }

    public function test_missing_tenant_context_fails_closed(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->queue(EmailMessageType::Test, self::TESTER);

        $this->assertSame(0, DB::table('email_messages')->count());
        $this->expectException(TenantContextMissing::class);
        app(EmailDelivery::class)->queue($this->draft(EmailMessageType::Test, self::TESTER), Actor::system());
    }

    public function test_secrets_are_never_logged_or_exposed(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = json_encode([$event->message, $event->context]);
        });

        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Test, self::TESTER)));

        $all = implode("\n", $logged).json_encode($this->inTenant($this->tenant, fn () => $this->ses->fresh()->toArray())).json_encode($this->inTenant($this->tenant, fn () => DB::table('audit_logs')->get()->all()));
        $this->assertStringNotContainsString(self::SES_SECRET, $all);
        $this->assertStringNotContainsString(self::SES_ACCESS_KEY, $all);
        $this->assertStringContainsString('ses.call', implode("\n", $logged));
        $this->assertSame(['secret_access_key' => '[redacted]', 'access_key_id' => '[redacted]'], app(Redactor::class)->redact(['secret_access_key' => self::SES_SECRET, 'access_key_id' => self::SES_ACCESS_KEY]));
        // The signed request carries the key id in the credential scope only, never the secret.
        $this->assertStringNotContainsString(self::SES_SECRET, json_encode($this->sends()[0]->headers()).$this->sends()[0]->body());
    }

    public function test_sending_mode_off_makes_no_network_request(): void
    {
        $this->emailModes($this->tenant, 'off', 'live');
        $id = $this->queue(EmailMessageType::Test, self::TESTER);

        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('SENDING_MODE_OFF', $this->message($id)->block_reason);
        $this->assertSame(1, Artisan::call('email:test', ['--tenant' => 'shop', '--recipient' => [self::TESTER], '--from' => self::FROM, '--confirm' => 'SEND REAL TEST EMAIL']));
        try {
            $this->inTenant($this->tenant, fn () => app(SesClientFactory::class)->make($this->ses->fresh(), SesClientFactory::SEND_PURPOSE));
            $this->fail('The send client must not be built while sending is off.');
        } catch (EmailSendBlocked $e) {
            $this->assertSame('SENDING_MODE_OFF', $e->reason);
        }
        $this->assertSame([], $this->sesRequests);
        // Not even the credentials were decrypted for sending.
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'secret.decrypted')->where('metadata->purpose', 'email_send')->count()));
    }

    public function test_test_only_mode_never_reaches_a_non_allowlisted_address(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');

        $id = $this->queue(EmailMessageType::Transactional, self::CUSTOMER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('NOT_TEST_RECIPIENT', $this->message($id)->block_reason);
        $this->assertSame(1, Artisan::call('email:test', ['--tenant' => 'shop', '--recipient' => [self::CUSTOMER], '--from' => self::FROM, '--confirm' => 'SEND REAL TEST EMAIL']));
        $this->assertSame([], $this->sends());

        // A "test" message never goes to a customer, even in live mode.
        $this->emailModes($this->tenant, 'live', 'live');
        $this->assertSame('blocked', $this->send($this->queue(EmailMessageType::Test, self::CUSTOMER)));
        $this->assertSame([], $this->sends());

        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Test, self::TESTER)));
        $this->assertCount(1, $this->sends());
        $this->assertSame([self::TESTER], $this->sends()[0]->data()['Destination']['ToAddresses']);
    }

    public function test_marketing_is_blocked_while_iys_is_unknown_or_unavailable(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        // No İYS integration at all: unavailable. Being a test recipient is no exception for marketing.
        $this->emailRecipient($this->tenant, self::TESTER);
        $id = $this->queue(EmailMessageType::Marketing, self::TESTER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('IYS_UNAVAILABLE', $this->message($id)->block_reason);

        // İYS synced but no answer for this address: unknown.
        $this->emailRecipient($this->tenant, self::TESTER, ConsentStatus::Unknown);
        $id = $this->queue(EmailMessageType::Marketing, self::TESTER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('IYS_UNKNOWN', $this->message($id)->block_reason);
        $this->assertSame([], $this->sends());

        // With İYS ONAY the same message goes out, with one-click unsubscribe headers.
        $this->emailRecipient($this->tenant, self::TESTER, ConsentStatus::Granted);
        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Marketing, self::TESTER)));
        $headers = collect($this->sends()[0]->data()['Content']['Simple']['Headers'])->pluck('Value', 'Name')->all();
        $this->assertStringStartsWith('<https://go.pratiktools.test/u/', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function test_transactional_mail_follows_its_own_policy(): void
    {
        $this->emailModes($this->tenant, 'live', 'live');
        $recipient = $this->emailRecipient($this->tenant, self::CUSTOMER);

        // No marketing consent needed for the customer's own transactional mail…
        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Transactional, self::CUSTOMER)));
        // …while marketing to the same address is refused.
        $marketing = $this->queue(EmailMessageType::Marketing, self::CUSTOMER);
        $this->assertSame('blocked', $this->send($marketing));
        $this->assertSame('IYS_UNAVAILABLE', $this->message($marketing)->block_reason);

        // An opt-out does not stop transactional mail; a hard bounce does.
        $this->inTenant($this->tenant, fn () => app(SuppressionService::class)->suppress($recipient, SuppressionSource::Unsubscribe, ConsentChannel::Email, 'test', Actor::system()));
        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Transactional, self::CUSTOMER)));
        $this->inTenant($this->tenant, fn () => app(SuppressionService::class)->suppress($recipient, SuppressionSource::SesBounce, ConsentChannel::Email, 'test', Actor::system()));
        $bounced = $this->queue(EmailMessageType::Transactional, self::CUSTOMER);
        $this->assertSame('blocked', $this->send($bounced));
        $this->assertSame('SUPPRESSED', $this->message($bounced)->block_reason);
        $this->assertCount(2, $this->sends());
    }

    public function test_the_same_idempotency_key_is_stored_and_queued_once(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');

        $a = $this->queue(EmailMessageType::Test, self::TESTER, ['idempotencyKey' => 'order:1001:confirmation']);
        $b = $this->queue(EmailMessageType::Test, self::TESTER, ['idempotencyKey' => 'order:1001:confirmation']);

        $this->assertSame($a, $b);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count()));
        Queue::assertPushedOn('send', SendEmail::class);
        Queue::assertPushed(SendEmail::class, 1);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => DB::table('audit_logs')->where('action', 'email.send_requested')->count()));
    }

    public function test_a_duplicate_job_delivery_makes_one_network_call(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $id = $this->queue(EmailMessageType::Test, self::TESTER);

        $this->assertSame('sent', $this->send($id));
        $this->assertSame('skipped', $this->send($id));
        $this->inTenant($this->tenant, fn () => (new SendEmail($id, $this->tenant->id))->handle(app(EmailDelivery::class)));

        $this->assertCount(1, $this->sends());
        $row = $this->message($id);
        $this->assertSame('sent', $row->status);
        $this->assertSame('0107018f-ses-1', $row->provider_message_id);
        $this->assertSame(1, (int) $row->attempts);
        $tags = collect($this->sends()[0]->data()['EmailTags'])->pluck('Value', 'Name')->all();
        $this->assertSame($id, $tags['pt_message_id']);
    }

    public function test_throttling_is_retried_with_backoff_and_a_used_up_quota_defers(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $n = 0;
        $this->sesOverride = function ($r) use (&$n) {
            return str_ends_with($r->url(), '/outbound-emails') && ++$n === 1
                ? Http::response(['message' => 'Maximum sending rate exceeded.'], 429, ['x-amzn-ErrorType' => 'TooManyRequestsException:'])
                : null;
        };
        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Test, self::TESTER)));
        $this->assertCount(2, $this->sends());
        $this->assertNotEmpty($this->sesSleeps);

        $this->sesOverride = fn ($r) => str_ends_with($r->url(), '/outbound-emails')
            ? Http::response(['message' => 'Daily message quota exceeded.'], 400, ['x-amzn-ErrorType' => 'ThrottlingException:'])
            : null;
        $this->sesRequests = [];
        $id = $this->queue(EmailMessageType::Test, self::TESTER);
        $this->assertSame('deferred', $this->send($id));
        $this->assertCount(1, $this->sends(), 'A used-up daily quota is not retried in a loop.');
        $row = $this->message($id);
        $this->assertSame('QUOTA_EXHAUSTED', $row->block_reason);
        $this->assertTrue(now()->lt($row->next_attempt_at));
        // Deferred messages are not sent before their time, even if the job runs.
        $this->assertSame('skipped', $this->send($id));
    }

    public function test_provider_5xx_is_retried_then_deferred(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $n = 0;
        $this->sesOverride = function ($r) use (&$n) {
            return str_ends_with($r->url(), '/outbound-emails') && ++$n === 1 ? Http::response(['message' => 'x'], 503, ['x-amzn-ErrorType' => 'ServiceUnavailable:']) : null;
        };
        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Test, self::TESTER)));
        $this->assertCount(2, $this->sends());

        $this->sesOverride = fn ($r) => str_ends_with($r->url(), '/outbound-emails') ? Http::response(['message' => 'x'], 500, ['x-amzn-ErrorType' => 'InternalFailure:']) : null;
        $this->sesRequests = [];
        $id = $this->queue(EmailMessageType::Test, self::TESTER);
        $this->assertSame('deferred', $this->send($id));
        $this->assertCount(3, $this->sends());
        $this->assertSame('PROVIDER_RETRYABLE', $this->message($id)->block_reason);
    }

    public function test_an_ambiguous_timeout_is_never_resent_and_never_falls_back(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $fallback = new class implements EmailProviderFactory
        {
            public int $sends = 0;

            public function make(TenantIntegration $integration, string $purpose, ?Actor $actor = null): EmailProvider
            {
                $factory = $this;

                return new class($factory) implements EmailProvider
                {
                    public function __construct(private readonly object $factory) {}

                    public function name(): string
                    {
                        return 'fake_esp';
                    }

                    public function capabilities(): EmailCapabilities
                    {
                        return new EmailCapabilities(EmailCapability::SendEmail, EmailCapability::RawHtml, EmailCapability::CustomHeaders);
                    }

                    public function test_connection(): ConnectionResult
                    {
                        return new ConnectionResult(true);
                    }

                    public function send(EmailSendAuthorization $authorization, OutboundEmail $email): ProviderSendResult
                    {
                        $this->factory->sends++;

                        return new ProviderSendResult('fake-1');
                    }

                    public function sendBatch(array $messages): array
                    {
                        return [];
                    }

                    public function health(): array
                    {
                        return [];
                    }
                };
            }

            public function capabilities(TenantIntegration $integration): EmailCapabilities
            {
                return new EmailCapabilities(EmailCapability::SendEmail, EmailCapability::RawHtml, EmailCapability::CustomHeaders);
            }
        };
        app(EmailProviderResolver::class)->register('fake_esp', $fallback);
        $secondary = $this->createIntegration($this->tenant, 'fake-secret-value-0001', 'fake_esp', 'email');
        $this->inTenant($this->tenant, fn () => $secondary->forceFill(['status' => 'connected', 'scopes' => [EmailSendGuard::SCOPE], 'settings' => ['email_role' => 'secondary']])->save());
        $this->assertSame($secondary->id, $this->inTenant($this->tenant, fn () => app(EmailProviderResolver::class)->secondary()?->id));

        $this->sesOverride = fn ($r) => str_ends_with($r->url(), '/outbound-emails')
            ? (Http::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received'))($r)
            : null;
        $id = $this->queue(EmailMessageType::Test, self::TESTER);

        $this->assertSame('failed', $this->send($id));
        $this->assertSame('skipped', $this->send($id));
        $row = $this->message($id);
        $this->assertSame('AMBIGUOUS_TIMEOUT', $row->error_code);
        $this->assertSame($this->ses->id, $row->integration_id);
        $this->assertCount(1, $this->sends(), 'An ambiguous send is not retried: SES may already have accepted it.');
        $this->assertSame(0, $fallback->sends, 'No automatic fallback send to the secondary provider.');
    }

    public function test_unverified_sender_is_blocked_without_a_request(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $pending = $this->inTenant($this->tenant, function () {
            DB::table('email_sender_identities')->where('id', $this->sender)->update(['verification_status' => 'pending']);

            return $this->sender;
        });
        $id = $this->queue(EmailMessageType::Test, self::TESTER, ['senderIdentityId' => $pending]);

        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('SENDER_NOT_VERIFIED', $this->message($id)->block_reason);
        $this->assertSame([], $this->sends());
    }

    public function test_malformed_headers_and_injection_attempts_are_refused_before_storage(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $cases = [
            'INVALID_HEADER' => ['headers' => ['List-Unsubscribe' => "<https://x.test/u>\r\nBcc: victim@example.com"]],
            'HEADER_NOT_ALLOWED' => ['headers' => ['Bcc' => 'victim@example.com']],
            'INVALID_SUBJECT' => ['subject' => "Merhaba\r\nBcc: victim@example.com"],
        ];
        foreach ($cases as $reason => $overrides) {
            try {
                $this->queue(EmailMessageType::Test, self::TESTER, $overrides);
                $this->fail("{$reason} expected");
            } catch (InvalidEmailMessage $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        foreach (["qa@example.com\r\nBcc: victim@example.com", 'qa@example.com, victim@example.com', 'Victim <victim@example.com>'] as $to) {
            try {
                $this->queue(EmailMessageType::Test, $to);
                $this->fail('Invalid recipient expected');
            } catch (InvalidEmailMessage $e) {
                $this->assertSame('INVALID_RECIPIENT', $e->reason);
            }
        }

        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count()));
        $this->assertSame([], $this->sesRequests);
    }

    public function test_a_used_up_provider_quota_defers_without_a_request(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->inTenant($this->tenant, fn () => $this->ses->forceFill(['settings' => ['account' => ['sent_last_24h' => 200.0] + $this->ses->settings['account']] + $this->ses->settings])->save());
        $id = $this->queue(EmailMessageType::Test, self::TESTER);

        $this->assertSame('deferred', $this->send($id));
        $this->assertSame('QUOTA_EXHAUSTED', $this->message($id)->block_reason);
        $this->assertSame([], $this->sends());
        Queue::assertPushed(SendEmail::class, 2);
    }

    public function test_live_marketing_needs_production_access_and_fresh_account_status(): void
    {
        $this->emailModes($this->tenant, 'live', 'live');
        $this->emailRecipient($this->tenant, self::CUSTOMER, ConsentStatus::Granted);

        $id = $this->queue(EmailMessageType::Marketing, self::CUSTOMER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('PROVIDER_SANDBOX', $this->message($id)->block_reason);

        $this->inTenant($this->tenant, fn () => $this->ses->forceFill(['settings' => ['account' => ['checked_at' => now()->subDay()->toIso8601String(), 'production_access' => true] + $this->ses->settings['account']] + $this->ses->settings])->save());
        $id = $this->queue(EmailMessageType::Marketing, self::CUSTOMER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('PROVIDER_STATUS_UNKNOWN', $this->message($id)->block_reason);
        $this->assertSame([], $this->sends());
    }

    public function test_marketing_needs_the_unsubscribe_key_dkim_and_respects_the_frequency_cap(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->emailRecipient($this->tenant, self::TESTER, ConsentStatus::Granted);

        config(['email.unsubscribe.key' => null]);
        $id = $this->queue(EmailMessageType::Marketing, self::TESTER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('UNSUBSCRIBE_UNAVAILABLE', $this->message($id)->block_reason);

        config(['email.unsubscribe.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->inTenant($this->tenant, fn () => DB::table('email_sender_identities')->update(['dkim_status' => 'pending']));
        $id = $this->queue(EmailMessageType::Marketing, self::TESTER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('DKIM_NOT_VERIFIED', $this->message($id)->block_reason);

        $this->inTenant($this->tenant, fn () => DB::table('email_sender_identities')->update(['dkim_status' => 'verified']));
        $this->assertSame('sent', $this->send($this->queue(EmailMessageType::Marketing, self::TESTER)));
        $capped = $this->queue(EmailMessageType::Marketing, self::TESTER);
        $this->assertSame('blocked', $this->send($capped));
        $this->assertSame('FREQUENCY_CAP', $this->message($capped)->block_reason);
        $this->assertCount(1, $this->sends());
    }

    public function test_send_scope_and_connection_are_required(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->inTenant($this->tenant, fn () => $this->ses->forceFill(['scopes' => ['read_account']])->save());
        $id = $this->queue(EmailMessageType::Test, self::TESTER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('SEND_SCOPE_MISSING', $this->message($id)->block_reason);

        $this->inTenant($this->tenant, fn () => $this->ses->forceFill(['scopes' => [EmailSendGuard::SCOPE], 'status' => 'error'])->save());
        $id = $this->queue(EmailMessageType::Test, self::TESTER);
        $this->assertSame('blocked', $this->send($id));
        $this->assertSame('PROVIDER_NOT_CONNECTED', $this->message($id)->block_reason);
        $this->assertSame([], $this->sends());
    }

    public function test_the_provider_refuses_without_the_platform_kill_switch(): void
    {
        // Mutation-style: even with a valid authorization, the provider itself
        // checks EMAIL_SENDING_MODE and the authorization's scope.
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $id = $this->queue(EmailMessageType::Test, self::TESTER);
        [$provider, $authorization, $email] = $this->inTenant($this->tenant, function () use ($id) {
            $row = DB::table('email_messages')->where('id', $id)->first();
            $email = new OutboundEmail($row->id, $row->idempotency_key, EmailMessageType::Test, $row->to_email, $row->from_email, $row->from_name, $row->reply_to, $row->subject, $row->html_body, $row->text_body);
            $authorization = app(EmailSendGuard::class)->authorize($this->ses->fresh(), $row, $email);

            return [app(EmailProviderResolver::class)->provider($this->ses->fresh(), 'email_send'), $authorization, $email];
        });

        config(['email.sending_mode' => 'off']);
        try {
            $provider->send($authorization, $email);
            $this->fail('The provider must refuse while the kill switch is off.');
        } catch (EmailSendBlocked $e) {
            $this->assertSame('SENDING_MODE_OFF', $e->reason);
        }

        config(['email.sending_mode' => 'test_only']);
        $other = new OutboundEmail((string) Str::uuid7(), 'k-other-0001', EmailMessageType::Test, 'someone@example.net', self::FROM, null, null, 'x', null, 'y');
        try {
            $provider->send($authorization, $other);
            $this->fail('An authorization covers exactly one message and recipient.');
        } catch (EmailSendBlocked $e) {
            $this->assertSame('AUTHORIZATION_MISMATCH', $e->reason);
        }
        $this->assertSame([], $this->sends());

        $this->assertSame('0107018f-ses-1', $provider->send($authorization, $email)->providerMessageId);
    }

    public function test_health_and_metrics_never_leak_secrets(): void
    {
        $this->emailModes($this->tenant, 'test_only', 'internal');
        $this->send($this->queue(EmailMessageType::Test, self::TESTER));

        $health = $this->getJson('/health')->json();
        $this->assertArrayHasKey('email', $health['checks']);
        $this->assertSame('ok', $health['checks']['email']['status']);
        $this->assertNotSame('fail', $health['checks']['email']['status']);

        [$owner] = [User::query()->where('email', 'owner@shop.test')->firstOrFail()];
        $metrics = $this->api('GET', "/api/v1/integrations/{$this->ses->id}/email-metrics", $this->tokenFor($owner, $this->tenant))->assertOk()->json('data');
        $this->assertSame('test_only', $metrics['sending_mode']);
        $this->assertSame(1, $metrics['today']['sent']);
        $this->assertFalse($metrics['account']['production_access']);

        $everything = json_encode($health).json_encode($metrics);
        foreach ([self::SES_SECRET, self::SES_ACCESS_KEY, self::TESTER, 'encrypted_secret'] as $needle) {
            $this->assertStringNotContainsString($needle, $everything);
        }
    }

    public function test_every_email_table_forces_row_level_security(): void
    {
        $tables = ['email_sender_identities', 'email_templates', 'email_template_versions', 'email_audience_snapshots', 'email_audience_members',
            'email_messages', 'email_events', 'email_unsubscribes', 'email_usage_daily', 'consent_write_intents'];
        $rows = DB::connection('pgsql_owner')->table('pg_class')->whereIn('relname', $tables)->where('relkind', 'r')
            ->get(['relname', 'relrowsecurity', 'relforcerowsecurity']);

        $this->assertCount(count($tables), $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, "{$row->relname} must FORCE row level security");
        }
    }

    public function test_production_default_sending_mode_is_off(): void
    {
        $previous = getenv('EMAIL_SENDING_MODE');
        putenv('EMAIL_SENDING_MODE');
        unset($_ENV['EMAIL_SENDING_MODE'], $_SERVER['EMAIL_SENDING_MODE']);
        try {
            $config = require base_path('config/email.php');
            $this->assertSame('off', $config['sending_mode']);
        } finally {
            if ($previous !== false) {
                putenv("EMAIL_SENDING_MODE={$previous}");
            }
        }
        $this->assertStringContainsString("EMAIL_SENDING_MODE=off\n", (string) file_get_contents(base_path('.env.production.example')));
        $this->assertStringContainsString("EMAIL_SENDING_MODE=off\n", (string) file_get_contents(base_path('.env.example')));
    }

    public function test_the_read_only_account_check_stores_quota_and_sandbox_status(): void
    {
        $this->assertSame(0, Artisan::call('email:ses', ['action' => 'check', '--tenant' => 'shop']));
        $account = $this->inTenant($this->tenant, fn () => $this->ses->fresh()->settings['account']);

        $this->assertFalse($account['production_access']);
        $this->assertSame(200.0, (float) $account['max_24h_send']);
        $this->assertSame(1.0, (float) $account['max_send_rate']);
        $this->assertSame(['GET'], array_values(array_unique(array_map(fn ($r) => $r->method(), $this->sesRequests))));
        $this->assertSame([], $this->sends());

        $this->assertSame(0, Artisan::call('email:sender', ['action' => 'refresh', '--tenant' => 'shop']));
        $identity = $this->inTenant($this->tenant, fn () => DB::table('email_sender_identities')->first());
        $this->assertSame('verified', $identity->dkim_status);
        $this->assertSame('aligned', $identity->spf_status);
        $records = collect(json_decode($identity->dns_records, true))->groupBy('purpose');
        $this->assertCount(3, $records['dkim']);
        $this->assertSame('tok1.dkim.amazonses.com', $records['dkim'][0]['value']);
        $this->assertSame('10 feedback-smtp.eu-central-1.amazonses.com', $records['mail_from'][0]['value']);
    }
}
