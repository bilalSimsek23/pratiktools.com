# İYS ViA (OTP form)

How PratikTools prepares for İYS ViA consent acquisition, what is verified
in the İYS portal, and what is still unknown. Nothing in the code base
contacts ViA or İYS today. The consent strategy and the reconciliation
pipeline are described in
[../production/consent-via-reconciliation.md](../production/consent-via-reconciliation.md).

## Status

### VERIFIED (seen in the İYS portal)

- Service provider: IGS MAĞAZACILIK ANONİM ŞİRKETİ, İYS No 620874, brand IGS.
- The ViA package is purchased and active.
- The OTP form type exists; the ETK approval type exists.
- A real form was created: "IGS E-Ticaret İletişim İzni" (OTP, ETK).
- İYS generated a Form ID for it.
- The form was submitted for approval ("Onaya Gönderildi").
- 2026-11-08 (operator): İYS **approved** the form and it is **published**
  ("Yayında"). The Form ID and the website URL were sent to İYS; İYS sent
  the official ViA Frame demo/documentation
  (https://via.iletiys.com.tr/frame-demo/demo.html) and will register the
  domain (DNS) on its side. **Waiting for İYS to confirm the domain
  activation.** The panel's form status is recorded separately (audited
  approval in Entegrasyonlar) and was not changed by this note.

### PENDING (unknown, not implemented, not guessed)

- İYS confirmation of the Frame domain (DNS) activation.
- Callback / event format beyond the Frame's browser events (and how events are authenticated).
- ViA Ret technical integration.
- İYS daily reconciliation report format (and a real sample).

## Configuration (per tenant)

Stored as the tenant integration `provider = via`, `capability =
consent_acquisition`, in `settings` (tenant-isolated, never readable by the
platform role; no secret is involved):

| Setting | IGS today | Notes |
|---|---|---|
| `iys_number` | 620874 | |
| `brand` | IGS | |
| `package_status` | active | |
| `form_type` | OTP | only OTP is supported |
| `approval_type` | ETK | only ETK is supported |
| `form_id` | from the İYS portal | never in code; read from STDIN; masked in the UI; not written to audit logs |
| `form_status` | pending_approval | see below |

The technical integration status is derived, not stored: `not_configured`,
`waiting_iys_approval`, `waiting_specification`, `blocked`, `ready`.

Operator (CLI, audited; nothing is sent to İYS):

```
printf '%s' '<Form ID from the portal>' | php artisan consent:via --tenant=igs \
  --iys-number=620874 --brand=IGS --package=active --form-type=OTP --approval-type=ETK \
  --form-status=pending_approval --form-id-stdin
php artisan consent:via --tenant=igs          # show (Form ID masked)
```

The command can only set `draft` or `pending_approval`.

## Form status

`draft → pending_approval → approved | rejected`, `approved → disabled`,
`rejected | disabled → pending_approval`.

- Only `approved` can be used for production consent acquisition.
- `approved` is recorded in the panel (Entegrasyonlar › İYS ViA) by an owner
  or admin with `integrations.manage`, after a recent password confirmation,
  with a note and the explicit confirmation that the İYS portal shows
  "Onaylandı". Every change is an audit event
  (`consent.via_form_status_changed`, from/to/note, no Form ID).
- Changing the Form ID of an approved form puts it back to
  `pending_approval`.
- Approval alone does not enable acquisition; the official transport must
  also exist (below).

## Readiness

`IysViaProvider::status()` — first failing condition wins:

| State | Level | Reason code |
|---|---|---|
| no configuration | BLOCKED | `VIA_NOT_CONFIGURED` |
| not OTP / not ETK | BLOCKED | `VIA_UNSUPPORTED_FORM` |
| rejected / disabled | BLOCKED | `VIA_FORM_REJECTED` / `VIA_FORM_DISABLED` |
| no Form ID | BLOCKED | `VIA_FORM_ID_MISSING` |
| draft | WAIT | `VIA_FORM_DRAFT` |
| pending approval | WAIT | `VIA_FORM_PENDING_APPROVAL` — "ViA OTP form is awaiting İYS approval." |
| test transport in production | BLOCKED | `VIA_FAKE_TRANSPORT_IN_PRODUCTION` |
| transport not configured | WAIT | `VIA_TRANSPORT_NOT_CONFIGURED` |
| otherwise | PASS | — |

The panel shows this as the external readiness row `IYS_VIA` (WAIT counts
as not ready for live sending) and as the İYS ViA card.

**IGS (2026-11-08):** the form is approved and published in the İYS
portal; the Frame is not live on the website until İYS confirms the domain
activation. ViA is IGS's channel for *new* consents only: it is shown as an
acquisition warning ("Yeni izin toplama kanalı") and does not block sending
to the audience that already consented (see
[../production/consent-merchant-verified.md](../production/consent-merchant-verified.md)).
Server-side OTP stays closed.

## OTP boundary

- `ViaOtpConsentProvider` (`IysViaOtpProvider`): `start(reference)`,
  `verify(reference, code)` for a pending `via_consent_requests` row. Both
  refuse unless the status is PASS.
- `ViaOtpTransport`: the network side. The only bound implementation is
  `NotConfiguredViaOtpTransport` (every call → `VIA_TRANSPORT_NOT_CONFIGURED`).
  No endpoint, payload, OTP API, frame URL or JavaScript API is assumed.
  A transport flagged `isFake()` (tests) is refused in production.
- DTOs: `ViaOtpStartRequest`, `ViaOtpStartResult`, `ViaOtpVerifyRequest`
  (code as `SensitiveValue`, never stored), `ViaOtpVerifyResult`. They are
  PratikTools' boundary; the future adapter maps the official API to them.
- Only a verified result with a provider reference, for the requested
  channel, creates ONAY. Anything else records a failure code and an attempt
  and creates nothing; the request stays pending.

## Consent record (ledger)

A verified ViA answer is an official observation (`source_provider = VIA`)
per channel (EMAIL, SMS, CALL stay separate; ETK is never one global flag)
with this provenance in `consents.evidence`:

`source = VIA`, `provider = IYS_VIA`, `form_type`, `form_id`,
`approval_type`, `channel`, `status`, `provider_reference`, `occurred_at`,
`recorded_at` (plus the correlation reference). `consent_events` keeps the
history. Idempotent per reference and provider reference.

## Precedence (unchanged)

- A local unsubscribe suppresses immediately and is never lifted by ViA or
  İYS; a later ViA ONAY is recorded as a conflict.
- An official RET (ViA Ret, İYS report) newer than the ViA ONAY blocks.
- Daily reconciliation stays required: changes recipients make in İYS
  channels arrive only through it. Its parser is not configured until the
  real report format is known.

## ViA Frame (Super Admin, per tenant)

ViA Frame is ViA's official form: a script from
`https://viaframe.iys.org.tr/widget/load-widgetloader/{FORM_ID}` plus a
`<div id="{FORM_ID}">` container on the tenant's own page. It is not an
iframe: the script injects its JS/CSS into the host page. ViA runs the OTP
and records the consent at İYS itself. PratikTools stores the configuration,
shows readiness and produces the embed code — nothing else:

- No call to ViA or İYS, no OTP, no consent ledger row, no browser event
  endpoint. Browser events (`on-frame-loaded-success`, `on-start-process*`,
  `on-complete-process-success|error`; documented payload: only
  `event.detail.message`) are not consent evidence.
- `IysViaOtpProvider` / server-side OTP and direct İYS writes stay closed
  (`IysViaProvider::status()` is unchanged; `IYS_ALLOW_PRODUCTION=false`).

Super Admin › Müşteriler › {tenant} › Entegrasyonlar › **İYS / ViA**
(`/tenants/{id}/integrations/via`, second factor; changes also need a recent
password). The configuration is the same tenant integration row
(`provider = via`, `capability = consent_acquisition`), read and written in
the tenant's own context (runtime role, RLS); the platform role gets no
access to integration settings.

| Setting | Notes |
|---|---|
| `iys_number`, `brand`, `package_status` | as the İYS portal shows |
| `form_id` | the ViA Form ID; must be a UUID here (lowercased); public configuration, not a secret |
| `form_type` / `approval_type` | saved as OTP / ETK |
| `form_status` | through the audited status action; "Onaylandı" needs the portal confirmation (portal: "Yayında") |
| `frame_origins` | `https://host[:port]` only — no path, query, fragment, credentials, IP or wildcard; lowercased, default port dropped, duplicates removed; at most 10 |

Readiness (`ViaFrameReadiness`, stored settings only):
`Yapılandırılmadı` (no row) · `Taslak / eksik` · `Yapılandırma hazır (ViA
tarafından doğrulanmadı)` (İYS no, brand, active package, OTP, UUID Form ID,
approved, ≥ 1 origin) · `Sorunlu` (rejected/disabled form, inactive package,
invalid Form ID or form type). The embed code is shown only when ready and
contains only the public Form ID.

Audit: `consent.via_configured` (changed keys, form status, `created`; never
the Form ID value), `consent.via_form_status_changed` (from/to/note).

## Activation checklist (when İYS answers)

1. Portal shows the form approved → admin records `approved` in the panel.
2. Official OTP integration instructions received → implement a real
   `ViaOtpTransport` from them (no guessing), with tests, and bind it.
3. Official callback/event format and a sample → event verification.
4. ViA Ret technical details → ViA Ret.
5. Real reconciliation report sample → parser.
6. One real test consent, with operator approval.

Until then: SES, OneSignal, ViA and İYS calls are zero; sending mode OFF.
