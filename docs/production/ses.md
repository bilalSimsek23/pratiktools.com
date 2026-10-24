# Amazon SES (e-mail delivery) rollout

PratikTools owns campaigns, audiences, consent, suppressions, templates,
events and usage. Amazon SES only delivers. The provider interface is
`App\Email\Contracts\EmailProvider`; SES is `App\Connectors\Ses\SesEmailProvider`.
Brevo, SendGrid, Mailgun or Klaviyo would be further connectors registered
with `EmailProviderResolver`.

Pipeline: campaign → frozen audience snapshot → message generation (Consent
Engine filter) → `email_messages` (one row per recipient, unique idempotency
key) → `SendEmail` job on the `send` queue → `EmailSendGuard` → provider →
SES MessageId → SNS events → webhook inbox → canonical events → suppressions.

## SES facts used (AWS documentation, checked 2026-10)

| Topic | Fact |
| --- | --- |
| API | SES API v2, `POST /v2/email/outbound-emails` (SendEmail), `GET /v2/email/account`, `GET /v2/email/identities/{id}`; endpoint `email.{region}.amazonaws.com`; SigV4 signing name `ses` |
| Content | Simple (subject, HTML, text, custom headers), Raw (MIME), Template. We use Simple. Header names: printable ASCII without `:`, ≤126 chars; values printable ASCII ≤995; name+value ≤996 |
| Tags | `EmailTags` name/value pairs; published with events |
| Batch | `SendBulkEmail` needs SES templates, so there is no multi-message raw send; SES recommends one recipient per `SendEmail` |
| Sandbox | per region; only to verified addresses/domains or the mailbox simulator; 200 messages / 24 h; 1 message / s; production access is requested per region (AWS answers within 96 h) |
| Quotas | rolling 24 h quota and max send rate, counted per recipient; exceeding gives `ThrottlingException` ("Daily message quota exceeded" / "Maximum sending rate exceeded"); v2 also `TooManyRequestsException` (429); wait and retry |
| Account | `GetAccount`: `ProductionAccessEnabled`, `SendingEnabled`, `EnforcementStatus` (HEALTHY, PROBATION, SHUTDOWN), `SendQuota` (Max24HourSend, MaxSendRate, SentLast24Hours), `SuppressionAttributes` |
| Reputation | keep bounces < 2 %, complaints < 0.1 %; review at 5 % / 0.1 %, possible pause at 10 % / 0.5 %; bounce rate counts hard bounces only |
| Events | via configuration set event destination (SNS, EventBridge, Firehose, CloudWatch, Pinpoint): Send, Delivery, Bounce (Permanent / Transient / Undetermined + subtypes), Complaint, Reject, Open, Click, DeliveryDelay, Rendering Failure, Subscription |
| Tracking | open pixel and link rewriting by SES on its tracking domain (`r.{region}.awstrack.me`) or a custom HTTPS redirect domain (CDN in front); only with a configuration set and open/click event types |
| Identities | domain verification = Easy DKIM: 3 CNAME `{token}._domainkey.{domain}` → `{token}.{SigningHostedZone}`; a verified domain covers its subdomains and addresses; identities are per region |
| MAIL FROM | subdomain of the verified domain, not used for sending or receiving; exactly one MX `10 feedback-smtp.{region}.amazonses.com` and TXT `"v=spf1 include:amazonses.com ~all"` |
| DMARC | TXT `_dmarc.{domain}`; start `p=none`, then quarantine, then reject; DKIM alignment via Easy DKIM, SPF alignment only with a custom MAIL FROM |
| SNS | HTTPS POST, `Content-Type: text/plain`, header `x-amz-sns-message-type`; verify the signature (SignatureVersion 1 = SHA1, 2 = SHA256) over the documented fields; certificate from an SNS URL over HTTPS; reject unexpected TopicArn |

SES has **no idempotency token** for SendEmail. PratikTools retries a send only
when it certainly did not reach SES (DNS, refused connection, connect timeout)
or SES answered with throttling / 5xx. A read timeout after the request was
written is `AMBIGUOUS_TIMEOUT`: the message is marked failed and never resent
automatically.

## Capabilities declared for SES

`send_email`, `raw_html`, `custom_headers`, `tags`, `provider_template`;
with a configuration set and an SNS topic also `webhook_events`,
`bounce_events`, `complaint_events`, `delivery_events`; `open_tracking` /
`click_tracking` only when the integration's `tracking` setting lists them
(the configuration set must publish those events). Not declared: `send_batch`.

## IAM (least privilege)

One IAM user per environment (Laravel Cloud has no instance role), access key
pair stored only in the tenant vault via `email:connect-ses`:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "SendFromTheTenantIdentityThroughOurConfigurationSet",
      "Effect": "Allow",
      "Action": "ses:SendEmail",
      "Resource": [
        "arn:aws:ses:eu-central-1:ACCOUNT_ID:identity/SENDING_DOMAIN",
        "arn:aws:ses:eu-central-1:ACCOUNT_ID:configuration-set/pratiktools-events"
      ],
      "Condition": {
        "StringEquals": { "ses:ApiVersion": "2" },
        "StringLike": { "ses:FromAddress": "*@SENDING_DOMAIN" }
      }
    },
    {
      "Sid": "ReadAccountAndIdentityStatus",
      "Effect": "Allow",
      "Action": ["ses:GetAccount", "ses:GetEmailIdentity", "ses:ListEmailIdentities"],
      "Resource": "*"
    }
  ]
}
```

No `ses:SendRawEmail`, no identity, configuration set, suppression list or
account write permissions. Identities, the configuration set, the SNS topic
and its subscription are created by a person in the AWS console, not by
PratikTools. Rotate the key by running `email:connect-ses --secret-stdin` again.

## Event destination

1. Configuration set `pratiktools-events` in the same region; event
   destination type SNS with Send, Delivery, Bounce, Complaint, Reject,
   DeliveryDelay (Open/Click only if tracking is wanted).
2. SNS topic, HTTPS subscription to
   `https://pratiktools.com/webhooks/amazon_ses/{tenant public_id}`,
   **raw message delivery off** (raw bodies are unsigned and are refused).
3. `email:connect-ses ... --configuration-set=pratiktools-events --sns-topic-arn=<topic ARN>`.
4. AWS posts a SubscriptionConfirmation; it is recorded as pending, never
   auto-confirmed. Confirm with `php artisan email:ses confirm-subscription --tenant=…`.

Verification: SigningCertURL must be `https://sns.{topic region}.amazonaws.com/….pem`,
the certificate must be for `sns.amazonaws.com` and currently valid, TopicArn
must be one of the tenant's topics, the timestamp within 24 h; the SNS
MessageId is the inbox replay key, the canonical event key dedupes
re-published copies. The inbox stores the SNS envelope; canonical events keep
codes only (no subject, headers, diagnostic text, full links or addresses).

## Suppression policy (PratikTools list is the source of truth)

| Event | Action |
| --- | --- |
| Bounce Permanent (any subtype, incl. already suppressed at SES) | `SES_BOUNCE`, blocks every message type |
| Bounce Transient | counted; 3 within 30 days → `SOFT_BOUNCE` |
| Bounce Undetermined | counted only |
| Complaint | `SES_COMPLAINT`, blocks marketing and test (not the customer's transactional mail) |
| Unsubscribe link | `UNSUBSCRIBE` + Consent Engine REVOKED (source PRATIKTOOLS) + pending İYS RET intent |

## Unsubscribe

`/u/{token}`: HMAC-signed token (tenant, recipient, issued at, nonce),
180-day validity, `EMAIL_UNSUBSCRIBE_KEY` (Cloud Secret, 32+ random bytes).
GET shows a confirm button; POST applies; `List-Unsubscribe` +
`List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058) on every
marketing message. Each token applies once. İYS RET is recorded in
`consent_write_intents` as `pending_write_disabled` and is not sent while
İYS writes are disabled (F2). Without the key, marketing fails closed
(`UNSUBSCRIBE_UNAVAILABLE`). The base URL is `EMAIL_UNSUBSCRIBE_BASE_URL`
(e.g. `https://go.pratiktools.com`), falling back to `APP_URL`.

## Sending safety

- Effective mode = stricter of `EMAIL_SENDING_MODE` (default `off`) and tenant
  `sending_mode`. Off: the guard blocks and the send credentials are not even
  decrypted; the SES provider re-checks the platform switch itself.
- `test_only`: only active e-mail test recipients (`email:test-recipient`).
  Type `test` is always allowlist-only and is the only consent exception;
  marketing is never exempt. In the SES sandbox test recipients must also be
  verified SES identities.
- Marketing: Consent Engine `ALLOWED` (İYS unknown/unavailable → blocked),
  DKIM-verified sender, one-click unsubscribe, frequency cap (1/day, 3/week),
  bounce/complaint guard (2 % / 0.08 % over 7 days after 200 sends), quota
  reserve 10 % for transactional, live marketing needs fresh account status
  and production access.
- All messages: `send_email` scope, connected integration, verified sender,
  allowlisted headers only (`List-Unsubscribe`, `List-Unsubscribe-Post`,
  `X-Entity-Ref-ID`), no CR/LF in any header field, one plain address per
  message, suppressions.
- No automatic provider fallback: a message is pinned to its integration. A
  fallback after a timeout can deliver twice and mixes sender reputation;
  switching provider is a per-campaign decision.

## Commands

```sh
# two lines on STDIN: access key id, secret access key (never as arguments)
php artisan email:connect-ses --tenant=igs --region=eu-central-1 --configuration-set=pratiktools-events --sns-topic-arn=<arn> --secret-stdin
php artisan email:ses check --tenant=igs                 # read-only GetAccount
php artisan email:sender add --tenant=igs --from=kampanya@<domain> --name="IGS" --reply-to=destek@<domain>
php artisan email:sender refresh --tenant=igs            # read-only GetEmailIdentity + DMARC lookup
php artisan email:sender dns --tenant=igs                # records to publish (never applied)
php artisan email:ses report --tenant=igs
php artisan email:ses warmup --tenant=igs --pool=89000   # plan only
php artisan email:test-recipient add --tenant=igs --email=<qa address> --label="QA" --approved-by="<name>"
php artisan email:ses scope --tenant=igs --grant=send_email
# only with EMAIL_SENDING_MODE=test_only and tenant sending_mode=internal:
php artisan email:test --tenant=igs --recipient=<qa address> --from=kampanya@<domain> --confirm="SEND REAL TEST EMAIL"
```

## Warm-up (IGS: ~89k consented marketing recipients)

Never send to the whole pool on day one. `email:ses warmup` proposes a plan:
start ~500/day with the most engaged (buyers of the last 90 days, recent
opens/clicks), grow ×1.5 per healthy day, hold the volume when the last day's
hard bounces > 2 % or complaints > 0.08 %, halve it at twice those values;
each day capped by 90 % of the SES 24 h quota. With these defaults 89k
recipients take 12 days with a 50k/day quota and 16 days with 10k/day, if
every day stays healthy. Nothing runs automatically.

## Usage

`email_usage_daily`: attempted, sent, delivered, bounced, complaints, bytes,
provider requests per tenant/provider/day. A usage report for later billing,
not an SES invoice.

## Production rollout (each step needs explicit approval)

1. Deploy with `EMAIL_SENDING_MODE=off`; run the migration
   (`php artisan migrate --database=pgsql_owner --force --isolated`).
2. AWS (by a person): IAM user + policy above, domain identity (Easy DKIM),
   custom MAIL FROM, configuration set, SNS topic and HTTPS subscription; DNS
   records published by the domain owner; production access request.
3. Cloud Secret `EMAIL_UNSUBSCRIBE_KEY`; `EMAIL_UNSUBSCRIBE_BASE_URL`.
4. `email:connect-ses`, `email:ses check`, `email:sender add/refresh/dns`,
   `email:ses confirm-subscription`.
5. Test mail: test recipient (verified in SES while in the sandbox), grant
   `send_email`, `EMAIL_SENDING_MODE=test_only`, tenant `sending_mode=internal`,
   `email:test`, then set both back to off.
6. Only after İYS is available (F2): pilot marketing under the warm-up plan.
