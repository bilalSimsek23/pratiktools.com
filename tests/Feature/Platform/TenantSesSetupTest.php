<?php

namespace Tests\Feature\Platform;

use App\Audit\Actor;
use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\RecipientType;
use App\Email\Data\EmailDraft;
use App\Email\DnsLookup;
use App\Email\EmailDelivery;
use App\Email\EmailLaunchReadiness;
use App\Email\EmailMessageType;
use App\Email\SenderIdentities;
use App\Email\SesReadiness;
use App\Email\TestEmails;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\PlatformRecentAuth;
use App\Jobs\SendEmail;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\InteractsWithSes;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Super Admin › Amazon SES, after the credentials: sender identities and
 * their DNS records (read from SES, never written), the SNS subscription
 * (explicit confirmation, the SubscribeURL never shown), unsubscribe
 * readiness, the test allowlist and test e-mail (blocked while sending is
 * off), the readiness card, the tenant-wide campaign readiness (never a
 * false GO) and the email:* commands, which now call the same services.
 */
final class TenantSesSetupTest extends TestCase
{
    use InteractsWithSes, InteractsWithTenants;

    private const SUBSCRIBE_TOKEN = 'subscribe-token-2336412f37fb687f5d51e6e2425f004a';

    private const SUBSCRIBE_URL = 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn%3Aaws%3Asns%3Aeu-central-1%3A123456789012%3Apratiktools-ses-events&Token='.self::SUBSCRIBE_TOKEN;

    private const QA = 'qa@example.com';

    private string $host;

    private PlatformAdmin $admin;

    private Tenant $tenant;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSes();
        $this->host = 'http://'.config('platform.admin_domain');
        config(['app.url' => 'https://pratiktools.test', 'site.owner_tenant' => null, 'email.sending_mode' => 'off']);
        $admin = new PlatformAdmin(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'a-very-long-admin-password', 'status' => 'active']);
        $admin->setConnection('pgsql_owner')->save();
        $this->admin = PlatformAdmin::query()->findOrFail($admin->id);
        $this->admin->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
        [$this->tenant] = $this->createTenant('igs');
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformTwoFactor::SESSION_KEY => $this->admin->id, PlatformRecentAuth::SESSION_KEY => time()]);
    }

    private function page(): string
    {
        return "{$this->host}/tenants/{$this->tenant->id}/integrations/ses";
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function act(string $path, array $input = []): TestResponse
    {
        return $this->asAdmin()->from($this->page())->call('POST', $this->page().$path, $input);
    }

    private function settings(): array
    {
        return $this->inTenant($this->tenant, fn () => TenantIntegration::query()->where('provider', 'amazon_ses')->firstOrFail()->settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function readiness(): array
    {
        return $this->inTenant($this->tenant, fn () => app(SesReadiness::class)->evaluate(TenantIntegration::query()->where('provider', 'amazon_ses')->first()));
    }

    /**
     * @return array<string, mixed>
     */
    private function launch(): array
    {
        return $this->inTenant($this->tenant, fn () => app(EmailLaunchReadiness::class)->evaluate(TenantIntegration::query()->where('provider', 'amazon_ses')->first()));
    }

    private function confirmation(string $url = self::SUBSCRIBE_URL): TestResponse
    {
        $envelope = $this->signSns([
            'Type' => 'SubscriptionConfirmation', 'MessageId' => (string) Str::uuid(), 'Token' => self::SUBSCRIBE_TOKEN, 'TopicArn' => self::SNS_TOPIC,
            'Message' => 'You have chosen to subscribe to the topic.', 'Timestamp' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'SubscribeURL' => $url, 'SignatureVersion' => '1', 'SigningCertURL' => self::SNS_CERT_URL,
        ]);

        // Absolute: after an admin request a relative URL would resolve to the admin host.
        return $this->call('POST', 'https://pratiktools.test/webhooks/amazon_ses/'.$this->tenant->public_id, [], [], [], [
            'CONTENT_TYPE' => 'text/plain; charset=UTF-8', 'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => 'SubscriptionConfirmation',
        ], (string) json_encode($envelope));
    }

    /**
     * Everything an operator can set up, with the production account; the
     * sending mode is left to the test.
     */
    private function fullyConfigured(): TenantIntegration
    {
        $integration = $this->connectSes($this->tenant, account: ['production_access' => true, 'max_24h_send' => 50000.0, 'max_send_rate' => 14.0]);
        $sender = $this->senderIdentity($this->tenant, $integration);
        $this->inTenant($this->tenant, function () use ($integration, $sender) {
            DB::table('email_sender_identities')->where('id', $sender)->update(['dmarc_status' => 'p=none', 'mail_from_domain' => 'bounce.news.example.com',
                'mail_from_status' => 'verified', 'spf_status' => 'aligned', 'last_checked_at' => now()]);
            $integration->forceFill(['settings' => [
                'configuration_set_check' => ['state' => 'ok', 'sending_enabled' => true, 'bounce_complaint' => true, 'sns_destinations' => [], 'open_click' => []],
                'sns_subscription' => ['state' => 'confirmed', 'topic_arn' => self::SNS_TOPIC, 'at' => now()->toIso8601String()],
            ] + (array) $integration->settings])->save();
        });
        $this->emailTestRecipient($this->tenant, self::QA);

        return $integration;
    }

    public function test_a_sender_is_recorded_and_its_dns_records_are_read_never_written(): void
    {
        $this->connectSes($this->tenant);
        $this->act('/senders', ['from_email' => self::FROM, 'from_name' => 'IGS', 'reply_to' => 'destek@example.com', 'identity' => ''])->assertSessionHasNoErrors();
        $this->assertSame([], $this->sesRequests, 'Saving a sender calls nothing at SES.');
        $sender = $this->inTenant($this->tenant, fn () => DB::table('email_sender_identities')->sole());
        $this->assertSame(['news.example.com', 'unknown', 'unknown'], [$sender->provider_identity_id, $sender->verification_status, $sender->dkim_status]);
        $this->assertSame('email.sender_identity_saved', DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'email.sender_identity_saved')->value('action'));

        $this->act("/senders/{$sender->id}/refresh")->assertSessionHasNoErrors()->assertSessionHas('status', 'Gönderici durumu okundu: doğrulama verified, DKIM verified, DMARC p=none.');
        $this->assertSame(['GET'], array_values(array_unique(array_map(fn (Request $r) => $r->method(), $this->sesRequests))), 'Only GetEmailIdentity: nothing is created at SES.');
        $this->assertSame(['/v2/email/identities/news.example.com'], array_map(fn (Request $r) => (string) parse_url($r->url(), PHP_URL_PATH), $this->sesRequests));

        $page = $this->asAdmin()->get($this->page())->assertOk();
        foreach (['tok1._domainkey.news.example.com', 'tok1.dkim.amazonses.com', 'feedback-smtp.eu-central-1.amazonses.com', 'v=spf1 include:amazonses.com ~all',
            '_dmarc.news.example.com', 'bounce.news.example.com', 'Kopyala', 'Doğrula / Yenile'] as $needle) {
            $page->assertSee($needle);
        }
        // MX: priority in its own column, value without it.
        $this->assertMatchesRegularExpression('#<code>feedback-smtp\.eu-central-1\.amazonses\.com</code>.*?<td>10</td>#s', (string) $page->getContent());
        $readiness = $this->readiness()['steps'];
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], [$readiness['sender_identity']['state'], $readiness['dkim']['state'], $readiness['dmarc']['state'], $readiness['mail_from']['state']]);
    }

    public function test_dkim_spf_dmarc_and_mail_from_problems_show_up(): void
    {
        $integration = $this->connectSes($this->tenant);
        $this->app->instance(DnsLookup::class, new class extends DnsLookup
        {
            public function txt(string $name): ?array
            {
                return [];
            }
        });
        $this->sesOverride = fn (Request $r) => str_starts_with((string) parse_url($r->url(), PHP_URL_PATH), '/v2/email/identities/') ? Http::response([
            'IdentityType' => 'DOMAIN', 'VerifiedForSendingStatus' => false, 'VerificationStatus' => 'PENDING',
            'DkimAttributes' => ['SigningEnabled' => true, 'Status' => 'FAILED', 'Tokens' => ['a1', 'a2', 'a3']], 'MailFromAttributes' => [],
        ]) : null;
        $id = $this->inTenant($this->tenant, fn () => app(SenderIdentities::class)->save($integration, self::FROM, 'IGS', null, null, Actor::system()));
        $this->act("/senders/{$id}/refresh")->assertSessionHasNoErrors();

        $steps = $this->readiness()['steps'];
        $this->assertSame(['pending', 'failed', 'missing', 'warn'], [$steps['sender_identity']['state'], $steps['dkim']['state'], $steps['dmarc']['state'], $steps['mail_from']['state']]);
        $row = $this->inTenant($this->tenant, fn () => DB::table('email_sender_identities')->sole());
        $this->assertSame(['provider_default', 'missing', 'not_configured'], [$row->spf_status, $row->dmarc_status, $row->mail_from_status]);
        $this->asAdmin()->get($this->page())->assertSee('_dmarc kaydı yok.')->assertSee('DKIM doğrulaması başarısız.');

        // An identity unknown to SES: a clear, safe message.
        $this->sesOverride = fn (Request $r) => Http::response(['message' => 'Identity does not exist'], 404, ['x-amzn-ErrorType' => 'NotFoundException']);
        $this->act("/senders/{$id}/refresh")->assertSessionHasErrors('ses_sender');
        $this->assertStringContainsString('AWS konsolunda', (string) session('errors')->first('ses_sender'));
    }

    public function test_an_sns_subscription_is_confirmed_only_on_request_and_its_url_never_shown(): void
    {
        $this->connectSes($this->tenant);
        $this->confirmation()->assertStatus(202);
        $this->assertSame('pending_confirmation', $this->settings()['sns_subscription']['state']);
        $this->assertSame([], array_values(array_filter($this->sesRequests, fn (Request $r) => str_contains($r->url(), 'ConfirmSubscription'))), 'Never confirmed automatically.');

        $page = $this->asAdmin()->get($this->page())->assertOk()->assertSee('Onay bekliyor')->assertSee('Aboneliği Onayla')
            ->assertSee('https://pratiktools.test/webhooks/amazon_ses/'.$this->tenant->public_id);
        $this->assertStringNotContainsString(self::SUBSCRIBE_TOKEN, (string) $page->getContent());
        $this->assertStringNotContainsString('ConfirmSubscription', (string) $page->getContent());
        $this->assertSame('pending', $this->readiness()['steps']['sns']['state']);

        $this->sesOverride = fn (Request $r) => str_contains($r->url(), 'Action=ConfirmSubscription') ? Http::response('<ConfirmSubscriptionResponse/>') : null;
        $this->act('/sns/confirm')->assertSessionHasNoErrors()->assertSessionHas('status', 'SNS aboneliği onaylandı.');
        $confirms = array_values(array_filter($this->sesRequests, fn (Request $r) => str_contains($r->url(), 'ConfirmSubscription')));
        $this->assertCount(1, $confirms);
        $this->assertSame(['GET', 'sns.eu-central-1.amazonaws.com'], [$confirms[0]->method(), parse_url($confirms[0]->url(), PHP_URL_HOST)]);
        $this->assertSame(['confirmed', self::SNS_TOPIC], [$this->settings()['sns_subscription']['state'], $this->settings()['sns_subscription']['topic_arn']]);
        $this->assertSame('ok', $this->readiness()['steps']['sns']['state']);
        $this->assertEquals(['topic_arn' => self::SNS_TOPIC], json_decode((string) DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'email.sns_subscription_confirmed')->value('metadata'), true));

        // Nothing pending any more.
        $this->act('/sns/confirm')->assertSessionHasErrors('ses_sns');
        $everything = implode("\n", $this->logs).DB::connection('pgsql_platform')->table('audit_logs')->get(['metadata'])->toJson().json_encode(session()->all())
            .json_encode($this->settings());
        $this->assertStringNotContainsString(self::SUBSCRIBE_TOKEN, $everything);
    }

    public function test_a_failed_confirmation_is_stored_as_error_and_an_unsafe_url_is_never_requested(): void
    {
        $this->connectSes($this->tenant);
        $this->confirmation();

        // AWS refuses (the fake answers 403): state error, a safe reason, no URL anywhere.
        $this->act('/sns/confirm')->assertSessionHasErrors('ses_sns');
        $this->assertSame(['error', 'AWS_REJECTED_HTTP_403'], [$this->settings()['sns_subscription']['state'], $this->settings()['sns_subscription']['error']]);
        $this->assertSame('failed', $this->readiness()['steps']['sns']['state']);
        $this->assertSame('AWS_REJECTED_HTTP_403', json_decode((string) DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'email.sns_subscription_failed')->value('metadata'), true)['reason']);
        $this->asAdmin()->get($this->page())->assertSee('AWS_REJECTED_HTTP_403')->assertSee('Aboneliği Onayla');

        // AWS unreachable: the exception (which quotes the URL) is not kept.
        $this->sesOverride = fn (Request $r) => str_contains($r->url(), 'ConfirmSubscription') ? throw new ConnectionException('cURL error 28 for '.$r->url()) : null;
        $this->act('/sns/confirm')->assertSessionHasErrors('ses_sns');
        $this->assertSame('AWS_UNREACHABLE', $this->settings()['sns_subscription']['error']);

        // A stored SubscribeURL that is not an AWS SNS host is never requested.
        $this->inTenant($this->tenant, fn () => DB::table('webhook_inbox')->where('provider', 'amazon_ses')->update(['payload' => DB::raw("jsonb_set(payload, '{SubscribeURL}', '\"https://evil.example.com/?Action=ConfirmSubscription&Token=x\"')")]));
        $this->sesRequests = [];
        $this->act('/sns/confirm')->assertSessionHasErrors('ses_sns');
        $this->assertStringContainsString('güvenilir', (string) session('errors')->first('ses_sns'));
        $this->assertSame([], $this->sesRequests);

        // The webhook handler itself never records an untrusted URL.
        $this->inTenant($this->tenant, fn () => TenantIntegration::query()->where('provider', 'amazon_ses')->first()->forceFill(['settings' => array_diff_key($this->settings(), ['sns_subscription' => 1])])->save());
        $this->confirmation('https://evil.example.com/?Action=ConfirmSubscription&Token=x')->assertStatus(202);
        $this->assertArrayNotHasKey('sns_subscription', $this->settings());
        $everything = implode("\n", $this->logs).DB::connection('pgsql_platform')->table('audit_logs')->get(['metadata'])->toJson().json_encode(session()->all());
        $this->assertStringNotContainsString(self::SUBSCRIBE_TOKEN, $everything);
    }

    public function test_a_missing_unsubscribe_key_blocks_and_the_key_is_never_shown(): void
    {
        $this->fullyConfigured();
        $key = (string) config('email.unsubscribe.key');
        $page = $this->asAdmin()->get($this->page())->assertOk()->assertSee('Yapılandırıldı')->assertSee('https://go.pratiktools.test/u/…', false);
        $this->assertStringNotContainsString(substr($key, 7), (string) $page->getContent());
        $this->assertTrue($this->readiness()['steps']['unsubscribe']['state'] === 'ok');

        config(['email.unsubscribe.key' => null]);
        $r = $this->readiness();
        $this->assertSame(['blocked', 'incomplete'], [$r['steps']['unsubscribe']['state'], $r['overall']]);
        $this->assertStringStartsWith('BLOCKED', (string) $r['steps']['unsubscribe']['detail']);
        $this->asAdmin()->get($this->page())->assertSee('Eksik – BLOCKED');
        $this->assertSame('BLOCKED', $this->launch()['checks']['UNSUBSCRIBE']['status']);

        config(['email.unsubscribe.key' => $key, 'email.unsubscribe.base_url' => 'http://go.pratiktools.test']);
        $this->assertSame('blocked', $this->readiness()['steps']['unsubscribe']['state']);
    }

    public function test_the_test_allowlist_is_managed_from_the_panel(): void
    {
        $this->connectSes($this->tenant);
        $this->act('/test-recipients', ['email' => 'QA@Example.com', 'label' => 'QA kutusu', 'approved_by' => 'Bilal'])->assertSessionHasNoErrors();
        $this->act('/test-recipients', ['email' => 'not an address', 'label' => 'x', 'approved_by' => 'y'])->assertSessionHasErrors('ses_recipient');
        $this->act('/test-recipients', ['email' => 'b@example.com', 'label' => '', 'approved_by' => 'y'])->assertSessionHasErrors('label');

        $rows = $this->inTenant($this->tenant, fn () => DB::table('test_recipients')->get(['provider_recipient_id', 'label', 'approved_by', 'active']));
        $this->assertSame([['qa@example.com', 'QA kutusu', 'Bilal', true]], $rows->map(fn ($r) => [$r->provider_recipient_id, $r->label, $r->approved_by, $r->active])->all());
        $this->asAdmin()->get($this->page())->assertSee('qa@example.com')->assertSee('QA kutusu')->assertSee('Kaldır');
        $added = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'email.test_recipient_added')->sole(['resource_id', 'metadata']);
        $this->assertSame(substr(hash('sha256', 'qa@example.com'), 0, 16), $added->resource_id);
        $this->assertStringNotContainsString('qa@example.com', (string) $added->metadata, 'The audit trail names the address by a hash only.');

        $this->act('/test-recipients/remove', ['email' => 'qa@example.com'])->assertSessionHasNoErrors();
        $this->assertFalse($this->inTenant($this->tenant, fn () => (bool) DB::table('test_recipients')->value('active')));
        $this->assertSame('missing', $this->readiness()['steps']['test_recipient']['state']);
        $this->assertSame(1, DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'email.test_recipient_removed')->count());
    }

    public function test_while_sending_is_off_the_test_email_is_blocked_and_never_queued(): void
    {
        Queue::fake([SendEmail::class]);
        $this->fullyConfigured();
        $page = $this->asAdmin()->get($this->page())->assertOk()->assertSee('Test gönderimi için platform gönderim modu kapalı.');
        $this->assertMatchesRegularExpression('/<button class="btn-primary" type="submit" disabled>Test e-postası gönder/', (string) $page->getContent());

        $this->act('/test-email', ['recipients' => [self::QA], 'from_email' => self::FROM, 'confirmation' => 'SEND REAL TEST EMAIL', 'acknowledge' => '1'])
            ->assertSessionHasErrors('ses_test_email');
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count()));
        Queue::assertNothingPushed();
        $this->assertSame([], $this->sesRequestsTo('/v2/email/outbound-emails'));
        $this->assertSame(0, DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'email.test_requested')->count());

        // Tenant mode off with the platform in test_only: still blocked.
        $this->emailModes($this->tenant, 'test_only', 'off');
        $this->assertSame('TENANT_SENDING_MODE_OFF', $this->inTenant($this->tenant, fn () => app(TestEmails::class)->blocker(TenantIntegration::query()->first())['code']));
    }

    public function test_in_test_only_mode_the_test_email_reaches_only_the_allowlist(): void
    {
        Queue::fake([SendEmail::class]);
        $this->fullyConfigured();
        $this->emailModes($this->tenant, 'test_only', 'internal');
        // Each attempt starts with a fresh rate budget (3 per 10 minutes).
        $send = function (array $input): TestResponse {
            $this->app['cache']->store()->flush();

            return $this->act('/test-email', $input + ['recipients' => [self::QA], 'from_email' => self::FROM, 'confirmation' => 'SEND REAL TEST EMAIL', 'acknowledge' => '1']);
        };

        $send(['recipients' => ['customer@example.com']])->assertSessionHasErrors('ses_test_email');
        $send(['confirmation' => 'yes'])->assertSessionHasErrors('ses_test_email');
        $send(['acknowledge' => null])->assertSessionHasErrors('acknowledge');
        $send(['from_email' => 'other@news.example.com'])->assertSessionHasErrors('ses_test_email');
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('email_messages')->count()));

        $send([])->assertSessionHasNoErrors()->assertSessionHas('status', '1 test e-postası kuyruğa alındı.');
        $message = $this->inTenant($this->tenant, fn () => DB::table('email_messages')->sole(['to_email', 'message_type', 'status']));
        $this->assertSame([self::QA, 'test', 'queued'], [$message->to_email, $message->message_type, $message->status]);
        Queue::assertPushed(SendEmail::class, 1);
        $audit = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'email.test_requested')->sole(['actor_type', 'metadata']);
        $this->assertSame('platform_admin', $audit->actor_type);
        $this->assertSame(1, json_decode((string) $audit->metadata, true)['recipients']);
    }

    public function test_readiness_moves_through_its_states_and_never_says_ready_while_sending_is_off(): void
    {
        $this->assertSame('not_configured', $this->readiness()['overall']);

        $this->act('', ['region' => self::SES_REGION, 'aws_access_key_id' => self::SES_ACCESS_KEY, 'aws_secret_access_key' => self::SES_SECRET]);
        $this->assertSame(['awaiting_test', 'Test bekliyor'], [$this->readiness()['overall'], $this->readiness()['overall_label']]);

        // The fake account is in the sandbox: someone must act.
        $this->act('/test');
        $this->assertSame(['action_required', 'failed'], [$this->readiness()['overall'], $this->readiness()['steps']['production_access']['state']]);
        $this->inTenant($this->tenant, fn () => TenantIntegration::query()->delete());

        $this->fullyConfigured();
        $r = $this->readiness();
        $this->assertSame(['infrastructure_ready', 'Altyapı hazır / Canlı gönderim kapalı'], [$r['overall'], $r['overall_label']]);
        $this->assertSame(['blocked', 'blocked'], [$r['steps']['test_email']['state'], $r['steps']['sending_mode']['state']]);
        $this->assertSame('Test gönderimi için platform gönderim modu kapalı.', $r['steps']['test_email']['detail']);
        $this->asAdmin()->get("{$this->host}/tenants/{$this->tenant->id}?tab=entegrasyonlar")->assertOk()->assertSee('Altyapı hazır / Canlı gönderim kapalı')
            ->assertSee('Canlı gönderim kapalı (platform: off, müşteri: off).');
        $this->assertStringNotContainsString('>Hazır<', (string) $this->asAdmin()->get($this->page())->getContent());

        // Live modes but no test e-mail yet: still not ready.
        $this->emailModes($this->tenant, 'live', 'live');
        $this->assertSame(['incomplete', 'missing'], [$this->readiness()['overall'], $this->readiness()['steps']['test_email']['state']]);

        // A sent test e-mail with live modes: Hazır.
        $integration = $this->inTenant($this->tenant, fn () => TenantIntegration::query()->firstOrFail());
        $sender = $this->inTenant($this->tenant, fn () => (string) DB::table('email_sender_identities')->value('id'));
        $this->inTenant($this->tenant, function () use ($integration, $sender) {
            $id = app(EmailDelivery::class)->queue(new EmailDraft($integration->id, $sender, EmailMessageType::Test, self::QA, 'Test', 'Metin'), Actor::system());
            app(EmailDelivery::class)->send($id, Actor::system());
        });
        $this->assertSame(['ready', 'Hazır'], [$this->readiness()['overall'], $this->readiness()['overall_label']]);

        // An SES enforcement problem: Müdahale gerekli again.
        $this->inTenant($this->tenant, fn () => $integration->forceFill(['settings' => ['account' => ['enforcement_status' => 'PROBATION'] + $integration->settings['account']] + $integration->settings])->save());
        $this->assertSame('action_required', $this->readiness()['overall']);
    }

    public function test_the_campaign_readiness_is_no_go_without_official_iys_consent_and_live_dispatch(): void
    {
        $this->fullyConfigured();
        $this->emailModes($this->tenant, 'live', 'live');
        // Ticimax MailIzin "granted" recipients: not official İYS ONAY.
        foreach (['a@example.com', 'b@example.com'] as $address) {
            $id = $this->emailRecipient($this->tenant, $address);
            $this->inTenant($this->tenant, fn () => app(ConsentStore::class)->observe($id, ConsentChannel::Email, RecipientType::Individual, ConsentSourceProvider::Ticimax,
                ConsentStatus::Granted, CarbonImmutable::now(), eventKey: 'ticimax:'.$address));
        }

        $launch = $this->launch();
        $this->assertFalse($launch['sendable']);
        $this->assertSame('NO-GO', $launch['verdict']);
        $this->assertSame(['recipients' => 2, 'allowed' => 0], array_intersect_key($launch['counts'], ['recipients' => 1, 'allowed' => 1]));
        // Default strategy (İYS API): Ticimax MailIzin is not a permission, so nobody may be sent to.
        $this->assertSame(['BLOCKED', 'delivery'], [$launch['checks']['CONSENT_BASIS']['status'], $launch['checks']['CONSENT_BASIS']['group']]);
        $this->assertStringContainsString('İYS API (doğrudan). Hazır değil', (string) $launch['checks']['CONSENT_BASIS']['detail']);
        $this->assertSame(['BLOCKED', '0 / 2'], [$launch['checks']['AUDIENCE']['status'], substr((string) $launch['checks']['AUDIENCE']['detail'], 0, 5)]);
        $this->assertSame(2, $launch['breakdown']['IYS_UNAVAILABLE']);
        $this->assertSame(['BLOCKED', 'Canlı dispatch henüz desteklenmiyor.'], [$launch['checks']['LIVE_DISPATCH']['status'], $launch['checks']['LIVE_DISPATCH']['detail']]);
        // Live modes without a sent test e-mail: SES itself is not ready yet either.
        $this->assertSame(['BLOCKED', 'PASS', 'BLOCKED', 'PASS'], [$launch['checks']['SES_READY']['status'], $launch['checks']['SENDING_MODE']['status'],
            $launch['checks']['TEMPLATE']['status'], $launch['checks']['DKIM']['status']]);
        // New consent collection is a warning, never a blocker.
        $this->assertSame(['WARN', 'acquisition'], [$launch['checks']['VIA_FRAME']['status'], $launch['checks']['VIA_FRAME']['group']]);

        $this->asAdmin()->get($this->page())->assertOk()->assertSee('11 Kasım E-posta Hazırlığı')->assertSee('NO-GO')->assertSee('Canlı dispatch henüz desteklenmiyor.')
            ->assertSee('Ticimax MailIzin bu stratejide izin yerine kullanılmaz')->assertSee('Yeni izin toplama (uyarı; gönderimi engellemez)');

        // With sending off, even more is blocked; there is no path to GO.
        $this->emailModes($this->tenant, 'off', 'off');
        $this->assertSame(['NO-GO', 'BLOCKED'], [$this->launch()['verdict'], $this->launch()['checks']['SENDING_MODE']['status']]);
    }

    public function test_the_email_commands_run_the_same_services(): void
    {
        // email:connect-ses: the pair from STDIN only, never echoed.
        $input = new ArrayInput(['command' => 'email:connect-ses', '--tenant' => 'igs', '--region' => 'eu-central-1', '--configuration-set' => 'pratiktools-events',
            '--sns-topic-arn' => [self::SNS_TOPIC], '--secret-stdin' => true]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, self::SES_ACCESS_KEY."\n".self::SES_SECRET."\n");
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput;
        $this->assertSame(0, Artisan::handle($input, $output));
        $this->assertStringNotContainsString(self::SES_SECRET, $output->fetch());
        $this->assertSame(['pending', 'console'], [$this->inTenant($this->tenant, fn () => TenantIntegration::query()->value('status')),
            DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'integration.secret_created')->value('actor_type')]);

        // email:ses check / scope.
        $this->assertSame(0, Artisan::call('email:ses', ['action' => 'check', '--tenant' => 'igs']));
        $this->assertSame('connected', $this->inTenant($this->tenant, fn () => TenantIntegration::query()->value('status')));
        $this->assertSame('missing', $this->settings()['configuration_set_check']['state'], 'The fake has no such configuration set.');
        $this->assertSame(0, Artisan::call('email:ses', ['action' => 'scope', '--tenant' => 'igs', '--grant' => 'send_email']));
        $this->assertContains('send_email', $this->inTenant($this->tenant, fn () => TenantIntegration::query()->firstOrFail()->scopes));
        $this->assertSame(2, Artisan::call('email:ses', ['action' => 'scope', '--tenant' => 'igs', '--grant' => 'everything']));

        // email:ses confirm-subscription.
        $this->assertSame(1, Artisan::call('email:ses', ['action' => 'confirm-subscription', '--tenant' => 'igs']));
        $this->confirmation();
        $this->sesOverride = fn (Request $r) => str_contains($r->url(), 'Action=ConfirmSubscription') ? Http::response('ok') : null;
        $this->assertSame(0, Artisan::call('email:ses', ['action' => 'confirm-subscription', '--tenant' => 'igs']));
        $this->assertSame('confirmed', $this->settings()['sns_subscription']['state']);

        // email:test-recipient and email:test.
        $this->assertSame(0, Artisan::call('email:test-recipient', ['action' => 'add', '--tenant' => 'igs', '--email' => self::QA, '--label' => 'QA', '--approved-by' => 'owner']));
        $this->assertSame(2, Artisan::call('email:test-recipient', ['action' => 'add', '--tenant' => 'igs', '--email' => 'x@example.com']));
        $this->assertSame(0, Artisan::call('email:test-recipient', ['action' => 'list', '--tenant' => 'igs']));
        $this->assertStringContainsString(self::QA, Artisan::output());
        $this->assertSame(1, Artisan::call('email:test', ['--tenant' => 'igs', '--recipient' => [self::QA], '--from' => self::FROM, '--confirm' => 'SEND REAL TEST EMAIL']));
        $this->assertStringContainsString('SENDING_MODE_OFF', Artisan::output());
        $this->assertSame(0, Artisan::call('email:test-recipient', ['action' => 'remove', '--tenant' => 'igs', '--email' => self::QA]));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => DB::table('test_recipients')->where('active', true)->count()));
    }

    public function test_controllers_never_shell_out(): void
    {
        foreach (['app/Http/Controllers/Admin/TenantSesController.php', 'app/Platform/TenantSes.php', 'app/Email/SesIntegrations.php', 'app/Email/SesAccountCheck.php',
            'app/Email/SnsSubscriptions.php', 'app/Email/TestRecipients.php', 'app/Email/TestEmails.php', 'app/Email/SesReadiness.php', 'app/Email/EmailLaunchReadiness.php'] as $file) {
            $code = (string) file_get_contents(base_path($file));
            foreach (['Artisan::', 'Process::', 'shell_exec', 'exec(', 'proc_open', 'passthru', 'system(', 'popen'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} must not use {$forbidden}");
            }
        }
    }
}
