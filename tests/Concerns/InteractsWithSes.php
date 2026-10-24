<?php

namespace Tests\Concerns;

use App\Connectors\Ses\SnsMessageVerifier;
use App\Consent\ConsentChannel;
use App\Consent\ConsentSourceProvider;
use App\Consent\ConsentStatus;
use App\Consent\ConsentStore;
use App\Consent\RecipientDirectory;
use App\Consent\RecipientType;
use App\Consent\Sync\ConsentSynchronizer;
use App\Email\DnsLookup;
use App\Email\EmailProviderResolver;
use App\Email\EmailSendGuard;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Fake Amazon SES API v2 (account, identity, outbound-emails; SigV4 header
 * required) and a fake SNS signing certificate, so SES events can be
 * signed exactly like SNS signs them. Records every request.
 */
trait InteractsWithSes
{
    protected const SES_REGION = 'eu-central-1';

    protected const SES_ACCESS_KEY = 'AKIAFAKETESTKEY00001';

    protected const SES_SECRET = 'fake/ses+secret-key-never-real-0123456789';

    protected const SNS_TOPIC = 'arn:aws:sns:eu-central-1:123456789012:pratiktools-ses-events';

    protected const SNS_CERT_URL = 'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.pem';

    protected const FROM = 'kampanya@news.example.com';

    /** @var list<Request> */
    protected array $sesRequests = [];

    /** @var list<int> */
    protected array $sesSleeps = [];

    protected ?Closure $sesOverride = null;

    protected int $sesMessageCounter = 0;

    protected string $snsCertPem = '';

    protected \OpenSSLAsymmetricKey $snsKey;

    protected function setUpSes(): void
    {
        config([
            'email.ses.base_backoff_ms' => 10,
            'email.ses.max_send_rate' => 10000,
            'email.unsubscribe.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'email.unsubscribe.base_url' => 'https://go.pratiktools.test',
            'platform.rate_limits.providers.amazon_ses_read' => ['tenant' => ['capacity' => 10000, 'refill_per_second' => 10000]],
        ]);
        $this->app->instance('ses.sleep', function (int $ms) {
            $this->sesSleeps[] = $ms;
        });
        $this->app->forgetInstance(EmailProviderResolver::class);
        $this->app->instance(DnsLookup::class, new class extends DnsLookup
        {
            public function txt(string $name): ?array
            {
                return str_starts_with($name, '_dmarc.') ? ['v=DMARC1; p=none; rua=mailto:dmarc@example.com'] : [];
            }
        });

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']), $pem);
        $this->snsKey = $key;
        $this->snsCertPem = $pem;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->sesRequests[] = $request;
            if ($this->sesOverride !== null && ($r = ($this->sesOverride)($request)) !== null) {
                return $r;
            }
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($url === self::SNS_CERT_URL) {
                return Http::response($this->snsCertPem);
            }
            if (! str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'AWS4-HMAC-SHA256 Credential='.self::SES_ACCESS_KEY.'/')) {
                return Http::response(['message' => 'The security token included in the request is invalid.'], 403, ['x-amzn-ErrorType' => 'UnrecognizedClientException:http://internal.amazon.com/coral/com.amazon.coral.service/']);
            }

            return match (true) {
                $path === '/v2/email/account' => Http::response([
                    'ProductionAccessEnabled' => false, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY',
                    'SendQuota' => ['Max24HourSend' => 200.0, 'MaxSendRate' => 1.0, 'SentLast24Hours' => 3.0],
                    'SuppressionAttributes' => ['SuppressedReasons' => ['BOUNCE', 'COMPLAINT']], 'Details' => ['MailType' => 'MARKETING'],
                ]),
                str_starts_with($path, '/v2/email/identities/') => Http::response([
                    'IdentityType' => 'DOMAIN', 'VerifiedForSendingStatus' => true, 'VerificationStatus' => 'SUCCESS',
                    'DkimAttributes' => ['SigningEnabled' => true, 'Status' => 'SUCCESS', 'Tokens' => ['tok1', 'tok2', 'tok3'], 'SigningHostedZone' => 'dkim.amazonses.com'],
                    'MailFromAttributes' => ['MailFromDomain' => 'bounce.news.example.com', 'MailFromDomainStatus' => 'SUCCESS', 'BehaviorOnMxFailure' => 'USE_DEFAULT_VALUE'],
                ]),
                $path === '/v2/email/outbound-emails' => Http::response(['MessageId' => '0107018f-ses-'.(++$this->sesMessageCounter)]),
                default => Http::response(['message' => 'not faked'], 404, ['x-amzn-ErrorType' => 'NotFoundException']),
            };
        });
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $account
     */
    protected function connectSes(Tenant $tenant, array $scopes = ['read_account', 'read_identities', EmailSendGuard::SCOPE], array $account = []): TenantIntegration
    {
        $integration = $this->createIntegration($tenant, (string) json_encode(['access_key_id' => self::SES_ACCESS_KEY, 'secret_access_key' => self::SES_SECRET]), 'amazon_ses', 'email');

        return $this->inTenant($tenant, function () use ($integration, $scopes, $account) {
            $integration->forceFill(['status' => 'connected', 'scopes' => $scopes, 'settings' => [
                'region' => self::SES_REGION, 'configuration_set' => 'pratiktools-events', 'sns_topic_arns' => [self::SNS_TOPIC], 'email_role' => 'primary',
                'account' => $account + ['region' => self::SES_REGION, 'production_access' => false, 'sending_enabled' => true, 'enforcement_status' => 'HEALTHY',
                    'max_24h_send' => 200.0, 'max_send_rate' => 10000.0, 'sent_last_24h' => 0.0, 'checked_at' => CarbonImmutable::now()->toIso8601String()],
            ]])->save();

            return $integration;
        });
    }

    protected function senderIdentity(Tenant $tenant, TenantIntegration $integration, string $verification = 'verified', string $dkim = 'verified'): string
    {
        return $this->inTenant($tenant, function () use ($tenant, $integration, $verification, $dkim) {
            $id = (string) Str::uuid7();
            DB::table('email_sender_identities')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'integration_id' => $integration->id, 'provider' => 'amazon_ses', 'domain' => 'news.example.com',
                'from_email' => self::FROM, 'from_name' => 'Örnek Mağaza', 'reply_to_email' => 'destek@example.com', 'provider_identity_id' => 'news.example.com',
                'verification_status' => $verification, 'dkim_status' => $dkim, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    protected function emailTestRecipient(Tenant $tenant, string $email): void
    {
        $this->inTenant($tenant, fn () => DB::table('test_recipients')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'channel' => 'email', 'provider' => '*', 'provider_recipient_id' => strtolower($email),
            'label' => 'QA inbox', 'approved_by' => 'owner', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    protected function emailModes(Tenant $tenant, string $platform, string $tenantMode): void
    {
        config(['email.sending_mode' => $platform]);
        $this->inTenant($tenant, fn () => DB::table('tenant_settings')->update(['sending_mode' => $tenantMode]));
    }

    /**
     * A consent recipient; with $iys the İYS state is synced and recorded.
     */
    protected function emailRecipient(Tenant $tenant, string $email, ?ConsentStatus $iys = null): string
    {
        return $this->inTenant($tenant, function () use ($tenant, $email, $iys) {
            $directory = app(RecipientDirectory::class);
            $normalized = $directory->email($email);
            $id = $directory->ensure('email', [$normalized])[$normalized['address']];
            if ($iys !== null) {
                $integration = TenantIntegration::query()->where('provider', 'iys')->first()
                    ?? $this->createIntegration($tenant, (string) json_encode(['username' => 'u', 'password' => 'p']), 'iys', 'consent');
                DB::table('integration_sync_cursors')->insertOrIgnore([
                    'tenant_id' => $tenant->id, 'integration_id' => $integration->id, 'entity' => ConsentSynchronizer::ENTITY_IYS_STATUS,
                    'last_success_at' => now(), 'last_window_end' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($iys !== ConsentStatus::Unknown) {
                    app(ConsentStore::class)->observe($id, ConsentChannel::Email, RecipientType::Individual, ConsentSourceProvider::Iys, $iys, CarbonImmutable::now(),
                        eventKey: 'test:'.Str::random(8), consentDate: CarbonImmutable::parse('2025-01-10'));
                }
            }

            return $id;
        });
    }

    /**
     * An SNS envelope signed like SNS does (SignatureVersion 2, SHA256).
     *
     * @param  array<string, mixed>  $sesRecord
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function snsNotification(array $sesRecord, array $overrides = [], ?string $messageId = null): array
    {
        $m = $overrides + [
            'Type' => 'Notification', 'MessageId' => $messageId ?? (string) Str::uuid(), 'TopicArn' => self::SNS_TOPIC,
            'Message' => json_encode($sesRecord), 'Timestamp' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'SignatureVersion' => '2', 'SigningCertURL' => self::SNS_CERT_URL,
            'UnsubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=Unsubscribe&SubscriptionArn=x',
        ];

        return $this->signSns($m);
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    protected function signSns(array $m): array
    {
        unset($m['Signature']);
        openssl_sign(SnsMessageVerifier::stringToSign($m), $signature, $this->snsKey, ($m['SignatureVersion'] ?? '2') === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);

        return $m + ['Signature' => base64_encode($signature)];
    }

    /**
     * @param  array<string, mixed>  $extra  event object (e.g. ['bounce' => [...]])
     * @return array<string, mixed>
     */
    protected static function sesEvent(string $type, string $sesMessageId, string $recipient, array $extra = [], ?string $ourMessageId = null): array
    {
        return [
            'eventType' => $type,
            'mail' => [
                'timestamp' => '2026-10-24T10:00:00.000Z', 'messageId' => $sesMessageId, 'source' => self::FROM, 'destination' => [$recipient],
                'commonHeaders' => ['subject' => 'Gizli kampanya konusu'], 'tags' => array_filter(['pt_message_id' => $ourMessageId === null ? null : [$ourMessageId]]),
            ],
        ] + $extra;
    }

    protected function postSns(Tenant $tenant, array $envelope): TestResponse
    {
        return $this->call('POST', '/webhooks/amazon_ses/'.$tenant->public_id, [], [], [], [
            'CONTENT_TYPE' => 'text/plain; charset=UTF-8', 'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => $envelope['Type'] ?? 'Notification',
        ], (string) json_encode($envelope));
    }

    /**
     * @return list<Request>
     */
    protected function sesRequestsTo(string $path): array
    {
        return array_values(array_filter($this->sesRequests, fn (Request $r) => (string) parse_url($r->url(), PHP_URL_PATH) === $path));
    }
}
