# Consent: ViA + İYS daily reconciliation (F2-R)

Direct İYS API access needs a higher, paid İYS package. F2-R prepares the
alternative İYS offers: **ViA** (İYS consent frame / ViA Ret) to obtain and
revoke consent, and İYS **daily change reports** for changes made by recipients
through İYS's own citizen channels. The F2 direct API path is kept unchanged.

Nothing in this phase connects to ViA or writes to İYS. Sending mode stays OFF.

## Strategy

`tenant_settings.consent_strategy`

| Strategy | Decision source | Freshness | Ready when |
|---|---|---|---|
| `direct_api` (default) | İYS API (F2 status sync) | per row, `CONSENT_IYS_MAX_AGE_HOURS` | a full İYS status sync succeeded |
| `via_reconciliation` | ViA events + applied İYS change reports | tenant-wide: last applied report period end vs `IYS_RECONCILIATION_MAX_AGE` (hours) | ViA configured **and** policy set **and** a report applied **and** not stale |

Only an operator changes it (audited, reason required):

```
php artisan consent:strategy --tenant=igs                       # show
php artisan consent:strategy --tenant=igs --set=via_reconciliation --reason="..."
```

Switching never enables sending. Until the chosen strategy is ready, every
marketing decision is `IYS_UNAVAILABLE` (or `IYS_RECONCILIATION_STALE`).

## Precedence (SendEligibility)

1. Invalid address, marketplace relay address.
2. **Local suppression** (unsubscribe, bounce, complaint, manual): always blocks;
   never lifted by ViA, İYS or a report. Lifting is the existing audited manual act.
3. **Official RET** (individual or merchant) blocks immediately, even while stale.
4. Strategy not ready → `IYS_UNAVAILABLE`; stale reconciliation → `IYS_RECONCILIATION_STALE`.
5. Pending ViA acquisition without an official answer → `IYS_PENDING`.
6. Otherwise the F2 rules (merchant-only ONAY, unknown, store opt-out, shared address).

"Official state" = the latest observation (its *as of* time) among the sources
`IYS`, `VIA` and `IYS_RECONCILIATION`. On the same instant RET wins, also within one
source (`ConsentStore` never replaces a RET with an ONAY of the same instant).
A newer official ONAY after a local unsubscribe is recorded as a fact and as a
conflict (`OFFICIAL_ONAY_AFTER_LOCAL_RET`); the local block stays.

Consent is never frozen with a campaign audience: campaign audience reports,
automation actions and the e-mail send guard all re-evaluate it at decision time.

## Sources (vocabulary)

| Stored `source_provider` | F2-R category |
|---|---|
| `IYS` | DIRECT_API |
| `VIA` | VIA |
| `IYS_RECONCILIATION` | IYS_RECONCILIATION |
| `PRATIKTOOLS` | LOCAL_UNSUBSCRIBE |
| `TICIMAX`, `TICIMAX_NEWSLETTER` | LEGACY_TICIMAX |
| `LEAD_FORM` | LEAD_FORM |
| `MANUAL` | OPERATOR_IMPORT |

Stored values were not renamed (history and idempotency keys depend on them).
Every change is a `consent_events` row with old/new status (superseded state),
occurred/observed time, provider transaction id and, for reports,
`reconciliation_batch_id`.

## ViA

`App\Consent\Via\ViaConsentProvider` (status, frame, event verification),
`ViaConsentEvent` (DTO), `ViaConsentHandler` (completion), `ViaConsentRequests`.

The bound implementation is `UnconfiguredViaProvider`: it shows a stored frame
(integration `iys_via` / `consent_acquisition`, setting `frame_id`) but never
reports ViA as usable and refuses every event (`VIA_EVENT_FORMAT_UNVERIFIED`).
There is **no inbound ViA endpoint**: no official event format, signature
scheme or sample exists yet.

Handler rules (tested with a test-only provider):

- The event's frame must equal the tenant's configured frame; the tenant is
  never taken from the event, a parameter or a header.
- Idempotent per reference and per ViA transaction id.
- Consent success → official ONAY (`VIA`) and only then `NEWSLETTER_SUBSCRIBED`.
  Failure → request failed, no consent. A failed request cannot later become ONAY.
- ViA Ret success → official RET (`VIA`), unsubscribe intent `confirmed`.
  Failure → intent `failed`; the local suppression stays.

### Lead forms

`lead_forms.consent_mode`: `local_only` (default, F6 behaviour) or `via`
(panel: form page → İzin modu). In `via` mode an accepted submission with
consent creates the lead, the store-side `LEAD_FORM` evidence and a pending
`via_consent_requests` row; no İYS intent (ViA writes to İYS itself) and no
newsletter event until ViA succeeds. The public endpoint and its response are
unchanged; how the browser opens the ViA frame depends on the ViA docs.

### Unsubscribe

Unchanged order: local suppression and local RET first (immediate block), then
an İYS RET intent with `route` = `direct_api` or `via_ret` (with a correlation
`reference`). It stays `pending_write_disabled`. The suppressions page shows
Local RET / İYS reconciliation pending / İYS RET confirmed / write failed. An
applied report RET or a ViA Ret success confirms it.

## Daily reconciliation

`IysReconciliationImporter` (analyze, apply), `IysReconciliationParser`
(per format), `IysReconciliationParsers` (registry), `ReconciliationFile`
(guarded reader), `IysConsentChange` (row DTO). Tables
`iys_reconciliation_batches`, `iys_consent_changes` (FORCE RLS, tenant scoped,
not readable by the platform role).

- **Format**: `consent.reconciliation.parsers` is empty and
  `IYS_RECONCILIATION_FORMAT` unset, so every upload ends as `unsupported /
  FORMAT_NOT_CONFIGURED`. A parser is added only from the official format or a
  real sample. (The tests use a made-up format under `tests/Support`.)
- **Dry-run** (upload): streamed line by line, classified in chunks of 500 in one
  transaction. Changes no consent and does not refresh freshness.
- **Apply**: re-classifies against the then-current state and records the
  changes in one transaction; a batch is applied once.
- **Idempotency**: one batch per tenant and file sha256 (re-upload re-analyses an
  unapplied batch, is refused once applied); a change already applied from
  another report is `duplicate`; repeated rows in one file are `duplicate`.
- **Outcomes**: onay, ret, unchanged, conflict (older than the known official
  state / ONAY for a locally blocked address / same instant as a RET), invalid,
  unknown_recipient (no recipient is created from a report), marketplace_excluded,
  duplicate.
- **Untrusted input**: size limit (`IYS_RECONCILIATION_MAX_FILE_BYTES`, 50 MB),
  row limit, 4 KB line limit, invalid UTF-8 / NUL bytes → invalid row, BOM
  dropped, leading formula characters stripped from kept text, file name
  sanitized, file not retained, rows keep an HMAC of the address
  (`AUDIT_HASH_KEY`) and the recipient id only. The panel masks addresses.
- **Freshness**: the latest applied report's period end (+1 day) against
  `IYS_RECONCILIATION_MAX_AGE`. No default: the legal window is an operator
  decision.
- **Future sources**: `source` is `operator_upload`; SFTP, secure download or an
  official API can feed the same importer later. None is implemented.

Panel: **İYS mutabakat** (owner/admin upload and apply with recent password
confirmation; others with integrations access can view).

## Remaining external inputs (STOP conditions)

ViA account and Frame ID, ViA configuration, the official ViA event payload /
signature documentation and a sample, ViA Ret configuration, the actual İYS
daily change report (format and a sample), the freshness window
(`IYS_RECONCILIATION_MAX_AGE`), and a real test consent. None of these may be
guessed.
