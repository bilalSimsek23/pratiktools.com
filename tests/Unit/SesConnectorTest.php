<?php

namespace Tests\Unit;

use App\Connectors\Ses\SesEmailProvider;
use App\Connectors\Ses\SesEventNormalizer;
use App\Connectors\Ses\SesIdentityRecords;
use App\Connectors\Ses\SigV4Signer;
use App\Connectors\Ses\SnsMessageVerifier;
use App\Email\EmailEventType;
use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class SesConnectorTest extends TestCase
{
    public function test_sigv4_matches_the_aws_sdk(): void
    {
        foreach ([
            ['GET', '/v2/email/account', [], ''],
            ['GET', '/v2/email/identities/kampanya%40news.example.com', [], ''],
            ['GET', '/v2/email/identities', ['PageSize' => '100', 'NextToken' => 'a b/c+d'], ''],
            ['POST', '/v2/email/outbound-emails', [], '{"FromEmailAddress":"a@b.c","Content":{"Simple":{"Subject":{"Data":"Çok güzel"}}}}'],
        ] as [$method, $path, $query, $body]) {
            $qs = SigV4Signer::canonicalQuery($query);
            $signed = (new SignatureV4('ses', 'eu-central-1'))->signRequest(
                new Request($method, 'https://email.eu-central-1.amazonaws.com'.$path.($qs === '' ? '' : '?'.$qs), ['X-Amz-Content-Sha256' => hash('sha256', $body)], $body),
                new Credentials('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'),
            );
            $at = CarbonImmutable::createFromFormat('Ymd\THis\Z', $signed->getHeaderLine('X-Amz-Date'), 'UTC');
            $mine = SigV4Signer::sign($method, 'email.eu-central-1.amazonaws.com', $path, $query, $body, 'eu-central-1', 'ses', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', $at);

            $this->assertSame($signed->getHeaderLine('Authorization'), $mine['Authorization'], "{$method} {$path}");
        }
    }

    public function test_display_names_are_encoded_and_never_break_the_header(): void
    {
        $this->assertSame('"IGS Store" <a@b.co>', SesEmailProvider::from('a@b.co', 'IGS Store'));
        $this->assertSame('=?UTF-8?B?'.base64_encode('Örnek "Mağaza" <x>').'?= <a@b.co>', SesEmailProvider::from('a@b.co', 'Örnek "Mağaza" <x>'));
    }

    public function test_ses_events_map_to_canonical_events(): void
    {
        $mail = ['messageId' => 'ses-1', 'destination' => ['A@Example.com'], 'timestamp' => '2026-10-24T10:00:00.000Z', 'tags' => ['pt_message_id' => ['0192-abc']]];
        $cases = [
            ['Send', [], EmailEventType::Sent],
            ['Delivery', ['delivery' => ['recipients' => ['a@example.com'], 'processingTimeMillis' => 120]], EmailEventType::Delivered],
            ['Reject', ['reject' => ['reason' => 'Bad content']], EmailEventType::Rejected],
            ['Open', ['open' => ['isBotEvent' => 'Unlikely']], EmailEventType::Opened],
            ['Click', ['click' => ['link' => 'https://shop.example.com/p/1?email=a@example.com']], EmailEventType::Clicked],
            ['DeliveryDelay', ['deliveryDelay' => ['delayType' => 'MailboxFull', 'delayedRecipients' => [['emailAddress' => 'a@example.com']]]], EmailEventType::DeliveryDelayed],
            ['Rendering Failure', ['failure' => ['templateName' => 't']], EmailEventType::RenderingFailed],
        ];
        foreach ($cases as [$name, $extra, $type]) {
            $event = SesEventNormalizer::normalize(['eventType' => $name, 'mail' => $mail] + $extra);
            $this->assertSame($type, $event?->type, $name);
            $this->assertSame(['a@example.com'], $event->recipients);
            $this->assertSame('0192-abc', $event->details['pt_message_id']);
        }
        $click = SesEventNormalizer::normalize(['eventType' => 'Click', 'mail' => $mail, 'click' => ['link' => 'https://shop.example.com/p/1?email=a@example.com']]);
        $this->assertSame(['shop.example.com', '/p/1'], [$click->details['link_host'], $click->details['link_path']]);
        $this->assertStringNotContainsString('email=', json_encode($click->details));

        $legacy = SesEventNormalizer::normalize(['notificationType' => 'Bounce', 'mail' => $mail, 'bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'Suppressed', 'bouncedRecipients' => [['emailAddress' => 'a@example.com']]]]);
        $this->assertSame([EmailEventType::Bounced, 'hard'], [$legacy->type, $legacy->bounceClass]);
        $this->assertNull(SesEventNormalizer::normalize(['eventType' => 'Unknown', 'mail' => $mail]));
    }

    public function test_dns_records_follow_the_ses_documentation(): void
    {
        $r = SesIdentityRecords::from([
            'VerifiedForSendingStatus' => false, 'VerificationStatus' => 'PENDING',
            'DkimAttributes' => ['SigningEnabled' => true, 'Status' => 'PENDING', 'Tokens' => ['t1', 't2', 't3'], 'SigningHostedZone' => 'dkim.eu-central-1.amazonses.com'],
        ], 'mail.igs.example', 'eu-central-1');

        $this->assertSame(['pending', 'pending', 'not_configured', 'provider_default'], [$r['verification_status'], $r['dkim_status'], $r['mail_from_status'], $r['spf_status']]);
        $this->assertSame(['purpose' => 'dkim', 'type' => 'CNAME', 'name' => 't1._domainkey.mail.igs.example', 'value' => 't1.dkim.eu-central-1.amazonses.com'], $r['dns_records'][0]);
        $this->assertSame('_dmarc.mail.igs.example', end($r['dns_records'])['name']);
    }

    public function test_only_sns_certificate_urls_are_trusted(): void
    {
        $topic = 'arn:aws:sns:eu-central-1:123456789012:t';
        $this->assertTrue(SnsMessageVerifier::trustedCertUrl('https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-abc.pem', $topic));
        foreach ([
            'http://sns.eu-central-1.amazonaws.com/x.pem', 'https://sns.eu-central-1.amazonaws.com.evil.com/x.pem', 'https://evil.com/sns.eu-central-1.amazonaws.com/x.pem',
            'https://sns.eu-central-1.amazonaws.com/x.txt', 'https://user@sns.eu-central-1.amazonaws.com/x.pem', 'https://sns.eu-central-1.amazonaws.com:8443/x.pem',
            'https://sns.us-east-1.amazonaws.com/x.pem',
        ] as $url) {
            $this->assertFalse(SnsMessageVerifier::trustedCertUrl($url, $topic), $url);
        }
    }
}
