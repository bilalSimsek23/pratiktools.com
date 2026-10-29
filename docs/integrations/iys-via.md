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

### PENDING (unknown, not implemented, not guessed)

- İYS template/form approval.
- Official OTP integration instructions.
- Official endpoint / SDK / frame details.
- Callback / event format (and how events are authenticated).
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

**IGS today: WAIT (`VIA_FORM_PENDING_APPROVAL`); production consent
acquisition blocked.**

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

## Activation checklist (when İYS answers)

1. Portal shows the form approved → admin records `approved` in the panel.
2. Official OTP integration instructions received → implement a real
   `ViaOtpTransport` from them (no guessing), with tests, and bind it.
3. Official callback/event format and a sample → event verification.
4. ViA Ret technical details → ViA Ret.
5. Real reconciliation report sample → parser.
6. One real test consent, with operator approval.

Until then: SES, OneSignal, ViA and İYS calls are zero; sending mode OFF.
