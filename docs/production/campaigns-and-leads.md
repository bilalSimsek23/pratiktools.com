# Campaigns, content and lead capture (F6)

F6 completes **Campaign → Audience → Content → Consent → Planned delivery**
without sending anything. There is no campaign dispatcher: `scheduled` is an
intention, and readiness always reports `DISPATCH_NOT_AVAILABLE`.

## Reused, not duplicated

| Concern | Reused from |
| --- | --- |
| Templates, render pipeline, unsubscribe | F4 `EmailTemplates`, `TemplateRenderer`, `UnsubscribeTokens` |
| Sender identities | F4 `email_sender_identities` |
| Audience, snapshots | F5 `Segments` (snapshot with a purpose key `campaign:<id>`) |
| Consent decisions | F2 `SendEligibility` (İYS ONAY required, fail-closed) |
| Frequency cap | F5 `FrequencyCap` |
| Welcome flow | F5 automation trigger `newsletter_subscribed` |

## Templates

Draft → published → archived. A published version is immutable (DB
trigger); campaigns accept only published marketing versions. Each version:
subject, preheader, HTML, plain text, variables, metadata.

Variables: allowlist only (`customer.*`, `product.*`, `coupon.*`, `store.*`,
`campaign.name`, `unsubscribe_url`, `current_year`); no PHP, Blade, raw
output (`{{{ }}}`, `| raw`), expressions or object traversal. Marketing
versions must contain `{{ unsubscribe_url }}` in every body to be saved and
published.

HTML policy (`EmailHtmlPolicy`, allowlist): refused at save/publish and
removed at render — script, iframe, object/embed, form and inputs, base/link,
svg/math, `meta http-equiv`, `on*` handlers, URLs other than `https:`,
`mailto:`, `tel:`, `#` (no `javascript:`, `data:`, `http:`), CSS with
`expression()`, `javascript:`, `behavior`, `-moz-binding`, `@import` or
non-https `url()`. Preview and send share one pipeline: substitution →
sanitize → hidden preheader.

Preview: `GET /api/v1/email-template-versions/{id}/preview` with sample
values (desktop 640 px, mobile 375 px, plain text, subject, preheader). No
provider call; there is no test-send in F6.

## Campaigns

Types `newsletter | promotional | announcement` (all marketing; transactional
mail never goes through campaigns). States `draft → ready → scheduled ⇄
paused`, `cancelled`, `completed` (dispatcher only), `archived`.

`ready` requires the preparation checks and freezes the audience (snapshot
unique per campaign — a retry returns the same snapshot), content version and
sender; a DB trigger keeps them unchanged.

Readiness (`GET /api/v1/campaigns/{id}`, machine-readable):

| Check | Group | Blocks ready? |
| --- | --- | --- |
| CONTENT, AUDIENCE, SENDER, DKIM, UNSUBSCRIBE | preparation | yes |
| MAIL_FROM | advisory (WARN) | no |
| IYS, ELIGIBLE_AUDIENCE, SES_PRODUCTION_ACCESS, EMAIL_SENDING_MODE, DISPATCH | delivery | no, but `sendable` stays false |

Audience preview (`GET /api/v1/campaigns/{id}/audience`, counts only): raw
audience, customers without e-mail, valid identity, invalid, marketplace,
suppressed, consent blocked, İYS unknown/stale, frequency capped, currently
eligible, and the explanation "Marketing sending is blocked because IYS
consent could not be verified." while İYS is unavailable.

Dry-run (`campaign dry-run --campaign=<id>`, `POST /api/v1/campaigns/{id}/dry-run`):
the same plus planned e-mail count, inside a READ ONLY transaction.

## Lead capture (public API v1)

```
POST https://<platform host>/public/v1/forms/{publicId}/subscribe
Content-Type: application/json
Origin: one of the form's allowed origins

{
  "email": "ad@example.com",          required
  "consent": true,                    the checkbox state (true/false)
  "submission_id": "<uuid v4>",       recommended: idempotency per submit
  "page_url": "https://…/path",       only host and path are kept
  "locale": "tr",
  "utm_source": "…", "utm_medium": "…", "utm_campaign": "…",
  "website": ""                       honeypot: keep empty and hidden
}
```

| Status | Body | When |
| --- | --- | --- |
| 202 | `{"status":"received","message":"<form success message>"}` | accepted, duplicate, marketplace relay, shared address, suspected bot (identical) |
| 422 | `{"status":"invalid","message":"Please enter a valid e-mail address."}` | malformed address / body |
| 403 | `{"status":"forbidden"}` | Origin not allowed |
| 404 | `{"status":"unavailable",…}` | unknown or inactive form |
| 413 / 429 | `too_large` / `rate_limited` | over 4 KB / over 10 per IP per minute or 600 per form |

The public id (`lf_…`) only allows "submit to this form"; it is not a tenant
credential and nothing secret goes to the browser. CORS is answered per form
(exact allowed origins); no cookies, so no CSRF token.

What a submission does (locally, no external call):

- normalized address → consent recipient; marketplace relay addresses are kept
  apart; an address shared by several customers or under merge review is never
  linked; an unknown address becomes a lead customer; never by phone;
- with consent: a store-side `LEAD_FORM` observation (or `PENDING` for double
  opt-in) with evidence (form, consent text version and hash, placement, time —
  no IP), an İYS ONAY intent `pending_write_disabled`, and a
  `NEWSLETTER_SUBSCRIBED` event for F5. The checkbox is **not** İYS ONAY:
  marketing eligibility stays fail-closed until İYS confirms;
- idempotent: one submission per `submission_id` (else per address, consent and
  day), enforced by a unique index.

Double opt-in (store-side, not İYS): data model only (`pending → confirmed →
expired`); no confirmation e-mail is sent in F6.

### Browser snippet (example, not deployed)

```html
<form id="pt-newsletter">
  <input type="email" name="email" required autocomplete="email">
  <label><input type="checkbox" name="consent"> Kampanya ve duyurulardan e-posta ile haberdar olmak istiyorum.</label>
  <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
  <button type="submit">Abone ol</button>
  <p id="pt-newsletter-message" role="status"></p>
</form>
<script>
(function () {
  var form = document.getElementById('pt-newsletter');
  var url = 'https://pratiktools.com/public/v1/forms/lf_XXXXXXXXXXXXXXXXXXXXXXXXXX/subscribe';
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var params = new URLSearchParams(location.search);
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email: form.email.value,
        consent: form.consent.checked,
        submission_id: (self.crypto && crypto.randomUUID) ? crypto.randomUUID() : undefined,
        page_url: location.origin + location.pathname,
        locale: document.documentElement.lang || 'tr',
        utm_source: params.get('utm_source') || undefined,
        utm_medium: params.get('utm_medium') || undefined,
        utm_campaign: params.get('utm_campaign') || undefined,
        website: form.website.value
      })
    }).then(function (r) { return r.json(); }).then(function (d) {
      document.getElementById('pt-newsletter-message').textContent = d.message || '';
    });
  });
})();
</script>
```

## Commerce relink

`commerce:cart-relink [--dry-run]` (also nightly in the commerce
reconciliation): fills only NULL links — cart/order items by exact variant or
product external id, carts by the Ticimax member id identity, else by a
non-marketplace e-mail identity without a pending merge. Local database only.

## Commands

```sh
php artisan template create --tenant=igs --name="Bülten" --type=marketing
php artisan template version --tenant=igs --template=<id> --subject="…" --preheader="…" --text-file=a.txt --html-file=a.html
php artisan template publish|preview --tenant=igs --version-id=<id>
php artisan campaign create --tenant=igs --name="Kasım" --type=newsletter --segment=<id> --template-version=<id> --integration=<id> --sender=<id>
php artisan campaign list|report|dry-run --tenant=igs [--campaign=<id>]
php artisan campaign state --tenant=igs --campaign=<id> --to=ready|scheduled|paused|cancelled|archived [--at=…]
php artisan lead-form create --tenant=igs --name="Footer" --placement=footer --consent-text="…" --success="…" --origin=https://www.igs.net.tr
php artisan lead-form activate|deactivate|list --tenant=igs [--form=<id>]
php artisan lead:report --tenant=igs
php artisan commerce:cart-relink --tenant=igs [--dry-run]
```

API permissions: `campaigns.view|manage|publish`, `content.manage|publish`,
`forms.manage`, `leads.view` (owner/admin all; marketer view/manage campaigns,
manage content, view leads).

## Production steps (operator, each needs approval)

1. Deploy; `php artisan vault:verify-kek`; `php artisan migrate --database=pgsql_owner --force --isolated`.
2. Cloud Secret `EMAIL_UNSUBSCRIBE_KEY` (until then marketing campaigns cannot become ready).
3. Create the IGS form(s) with `--origin=https://www.igs.net.tr`, verify with a
   synthetic address, then activate. Connecting the live IGS popup/footer is a
   separate, approved frontend change.
