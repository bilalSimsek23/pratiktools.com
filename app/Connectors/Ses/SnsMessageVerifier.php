<?php

namespace App\Connectors\Ses;

use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use App\Webhooks\Contracts\DescribesWebhookEvents;
use App\Webhooks\Contracts\WebhookSignatureVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Authenticates SES events delivered by Amazon SNS over HTTPS, following
 * the SNS signature verification procedure:
 *
 * - the message type is Notification, SubscriptionConfirmation or
 *   UnsubscribeConfirmation (and matches x-amz-sns-message-type);
 * - TopicArn is one of the tenant's configured topics (unexpected topics
 *   are refused, so another AWS account cannot post to our URL);
 * - SigningCertURL is https on sns.{region}.amazonaws.com and ends in .pem;
 *   the certificate is fetched over HTTPS, cached, must be issued for
 *   sns.amazonaws.com and be currently valid;
 * - the signature over the documented fields (byte order, "name\nvalue\n")
 *   verifies with SHA1 (SignatureVersion 1) or SHA256 (2);
 * - the timestamp is within email.ses.sns.max_age_seconds (replays inside
 *   the window are dropped by the inbox through MessageId).
 *
 * Raw message delivery must stay off on the subscription: raw bodies are
 * unsigned and are refused.
 */
final class SnsMessageVerifier implements DescribesWebhookEvents, WebhookSignatureVerifier
{
    private const NOTIFICATION_FIELDS = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];

    private const CONFIRMATION_FIELDS = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Http $http,
    ) {}

    public function verify(Request $request, string $tenantId): string
    {
        $m = json_decode($request->getContent(), true);
        if (! is_array($m) || ! isset($m['Signature'], $m['SigningCertURL'], $m['SignatureVersion'])) {
            return 'missing';
        }

        $type = $m['Type'] ?? null;
        if (! in_array($type, ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)) {
            return 'invalid';
        }
        $header = $request->header('x-amz-sns-message-type');
        if ($header !== null && $header !== $type) {
            return 'invalid';
        }
        if (! is_string($m['TopicArn'] ?? null) || ! in_array($m['TopicArn'], $this->allowedTopics($tenantId), true)) {
            return 'invalid';
        }
        if (! $this->fresh($m['Timestamp'] ?? null)) {
            return 'invalid';
        }

        $algorithm = match ((string) $m['SignatureVersion']) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };
        $signature = base64_decode((string) $m['Signature'], true);
        $publicKey = $this->publicKey((string) $m['SigningCertURL'], (string) $m['TopicArn']);
        if ($algorithm === null || $signature === false || $publicKey === null) {
            return 'invalid';
        }

        return openssl_verify(self::stringToSign($m), $signature, $publicKey, $algorithm) === 1 ? 'valid' : 'invalid';
    }

    public function eventType(Request $request, array $payload): ?string
    {
        return is_string($payload['Type'] ?? null) ? $payload['Type'] : null;
    }

    public function eventId(Request $request, array $payload): ?string
    {
        return is_string($payload['MessageId'] ?? null) ? 'sns:'.$payload['MessageId'] : null;
    }

    /**
     * @param  array<string, mixed>  $m
     */
    public static function stringToSign(array $m): string
    {
        $fields = ($m['Type'] ?? null) === 'Notification' ? self::NOTIFICATION_FIELDS : self::CONFIRMATION_FIELDS;
        $out = '';
        foreach ($fields as $field) {
            if (array_key_exists($field, $m) && $m[$field] !== null) {
                $out .= $field."\n".$m[$field]."\n";
            }
        }

        return $out;
    }

    public static function trustedCertUrl(string $url, ?string $topicArn = null): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        if (preg_match('/^sns\.([a-z0-9-]+)\.amazonaws\.com(\.cn)?$/', (string) ($parts['host'] ?? ''), $host) !== 1 || ! str_ends_with((string) ($parts['path'] ?? ''), '.pem')) {
            return false;
        }

        // The certificate comes from the topic's own region.
        return $topicArn === null || (explode(':', $topicArn)[3] ?? null) === $host[1];
    }

    /**
     * @return list<string>
     */
    private function allowedTopics(string $tenantId): array
    {
        $settings = $this->context->run($tenantId, fn () => TenantIntegration::query()->where('provider', 'amazon_ses')->where('capability', 'email')->value('settings'));
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;

        return array_values(array_filter((array) (is_array($settings) ? ($settings['sns_topic_arns'] ?? []) : []), 'is_string'));
    }

    private function fresh(mixed $timestamp): bool
    {
        try {
            $at = CarbonImmutable::parse((string) $timestamp);
        } catch (Throwable) {
            return false;
        }

        return $at->gte(now()->subSeconds((int) config('email.ses.sns.max_age_seconds'))) && $at->lte(now()->addMinutes(5));
    }

    private function publicKey(string $url, string $topicArn): ?\OpenSSLAsymmetricKey
    {
        if (! self::trustedCertUrl($url, $topicArn)) {
            return null;
        }

        $pem = Cache::remember('sns-cert:'.hash('sha256', $url), (int) config('email.ses.sns.cert_cache_seconds'), function () use ($url) {
            try {
                $response = $this->http->timeout(5)->connectTimeout(3)->get($url);
            } catch (Throwable $e) {
                Log::warning('ses.sns_cert_unavailable', ['error' => $e::class]);

                return null;
            }

            return $response->successful() ? $response->body() : null;
        });
        if (! is_string($pem)) {
            Cache::forget('sns-cert:'.hash('sha256', $url));

            return null;
        }

        $cert = @openssl_x509_read($pem);
        $info = $cert === false ? false : openssl_x509_parse($cert);
        if ($cert === false || ! is_array($info) || ($info['subject']['CN'] ?? null) !== 'sns.amazonaws.com'
            || (int) ($info['validFrom_time_t'] ?? PHP_INT_MAX) > time() || (int) ($info['validTo_time_t'] ?? 0) < time()) {
            return null;
        }

        $key = openssl_pkey_get_public($cert);

        return $key === false ? null : $key;
    }
}
