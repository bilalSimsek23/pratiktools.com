# Marketing Automation SaaS

Multi-tenant marketing automation platform for Ticimax stores. This repository
is independent of the IGS admin application; IGS is the first tenant.

Current phase: **F2 – Consent Engine and İYS read-only shadow sync**, on top of
F1 (Ticimax read-only commerce sync) and F0 (tenancy, row-level security,
secret vault, audit log, queues, health, CI). Nothing is ever written to
Ticimax, İYS or any messaging provider.

## Architecture in one page

| Concern | How |
| --- | --- |
| Tenant isolation (database) | PostgreSQL row-level security with `FORCE ROW LEVEL SECURITY` on every tenant table. Policies compare `tenant_id` with `app_current_tenant()`, which reads the session setting `app.tenant_id`. No context = no rows, and writes fail. |
| Database roles | `saas_owner` owns the schema and runs migrations only. `saas_app` is the runtime role (not owner, `NOBYPASSRLS`). `saas_platform` serves the Super Admin panel with column-level grants and no access to personal data or secrets. |
| Tenant isolation (application) | `BelongsToTenant` global scope fails closed (`TenantContextMissing`), rejects writes for another tenant, and route model binding only resolves records of the current tenant. |
| Tenant context | `App\Tenancy\TenantContext`. Set from the tenant an API token was issued for (never from request input), from a job's `tenant_id`, from a console `--tenant` option or from a webhook URL. Mirrored into PostgreSQL on every connection, cleared after each request and job. |
| Secrets | `SecretVault`: per-tenant data key (DEK), AES-256-GCM, wrapped by the platform KEK behind `KeyEncryptionService`. Production: `cloud_secret` (KEK from a Laravel Cloud Secret, ids derived from the key); optional `aws_kms`; `local` for dev/test only. Ciphertext is bound to tenant + integration. Secrets are write-only in the API and every decrypt is audited. See docs/production/secret-vault-rotation.md. |
| Audit | `audit_logs` is append-only: no UPDATE/DELETE grants, a trigger, and a model guard. IP and user agent are stored as HMACs. |
| Queues | Redis + Horizon. Queues: `system`, `webhooks`, `sync`, `automation`, `send`. Tenant jobs use `TenantAwareJob` and carry `tenant_id`; a queue guard clears context around every job. |
| Rate limiting | Redis token buckets per `tenant:{tenant_id}:{provider}` plus optional platform-wide buckets (e.g. IYS per egress IP), consumed atomically. |
| Webhooks | `POST /webhooks/{provider}/{tenantPublicId}` stores into `webhook_inbox` (idempotent on `external_event_id`), then processes on the `webhooks` queue. |
| Super Admin | Separate host (`ADMIN_DOMAIN`), separate guard, mandatory TOTP 2FA, aggregate data only. Horizon is only reachable there. |
| Observability | JSON logs with `tenant_id`, `request_id`, `job_id`, `provider`, `run_id`, `error_class`; e-mails, phone numbers and secrets are redacted. `/healthz` (liveness), `/health` (database, Redis, Horizon, queue backlog). `ErrorReporter` / `Tracer` interfaces for Sentry/OpenTelemetry. |

## Commerce sync (F1)

| Concern | How |
| --- | --- |
| Provider boundary | `App\Commerce\Contracts\CommerceSource` (read-only, paged, opaque page tokens). `TicimaxCommerceConnector` is the first implementation; `CommerceSourceFactory` builds it from the tenant integration (settings: `service_url`, `storefront_url`; secret: the Ticimax member code, in the vault). |
| Ticimax client | Read-only operation allowlist, tenant token bucket, retry with exponential backoff and jitter, normalized errors (`auth`, `rate_limited`, `retryable`, `permanent`, `schema_mismatch`), WSDL cache, request id header, no SOAP trace in production, no parameters or bodies in logs. |
| Canonical data | `customers` + `customer_identities` (member id, e-mail, phone, newsletter id; deterministic matching only, conflicts go to `customer_merges` for review), `customer_stats`, `categories`, `products`, `product_variants`, `orders`, `order_items`, `carts`, `cart_items`. Ticimax permission flags are kept as source data (`source_consent`) only. |
| Marketplace orders | Stored and flagged (`is_marketplace`, `channel = marketplace`); never create a customer or identity and are excluded from customer stats. |
| Events | `customer_events`: `CUSTOMER_CREATED/UPDATED`, `ORDER_PLACED`, `ORDER_STATUS_CHANGED`, `CART_UPDATED`, versioned, unique per `(source, source_event_id)`. Events derived from history are `is_backfill`. No `CART_ABANDONED` (automation engine, F5). |
| Sync | `SyncRunner`: one window per run (full: backfill start; delta: cursor minus overlap), progress saved after every page, cursor advanced only after the whole window and never backwards. `SyncCommerceEntity` jobs on the `sync` queue work in 45 s slices and re-queue themselves. Scheduler entries exist but are off unless `COMMERCE_SYNC_SCHEDULE_ENABLED=true`. |
| Health and errors | `integration_sync_runs`, `integration_sync_cursors`, `integration_errors`; integration status `connected` / `syncing` / `error` / `action_required`. |

```sh
# secret from STDIN only (never as an argument)
printf '%s' "$TICIMAX_MEMBER_CODE" | php artisan ticimax:connect --tenant=demo \
    --service-url=https://www.example.com/Servis/ --storefront-url=https://www.example.com --secret-stdin
php artisan commerce:test --tenant=demo
php artisan commerce:sync --tenant=demo --entity=all --mode=full --inline
php artisan commerce:status --tenant=demo
php artisan commerce:preview --tenant=demo      # 10 records each, contact data masked
php artisan commerce:compare --tenant=demo      # counts and field-by-field check against the source
```

API: `POST /api/v1/integrations/{id}/test`, `GET …/sync-status`, `POST …/sync`, `GET …/preview`.

## Consent (F2, shadow mode)

| Concern | How |
| --- | --- |
| Model | `consent_recipients` (normalized address) ↔ `recipient_customers` (no customer merge; shared e-mails/phones are links), `consents` (latest observation per recipient, channel, recipient type and source provider), append-only `consent_events`, derived `consent_conflicts`, audited `suppressions` (lifted, never deleted). |
| Sources | `IYS` is authoritative. `TICIMAX` (MailIzin/SmsIzin and SelectUyeIzinGecmisi history), `TICIMAX_NEWSLETTER`, `LEAD_FORM`, `MANUAL`, `ONESIGNAL` (push, later), `KLAVIYO` (later) are evidence. |
| İYS connector | `ConsentProvider` / `IysConsentConnector`: read operations only (`ReadOnlyModeViolation` otherwise, before any request); encrypted cached tokens with a refresh lock and the 10/hour token budget; bulk status (1,000 per call, returns ONAY only), single status (1,000/hour/IP), changes pull with `after` cursor (7-day window), daily summary. Production only with registered static egress IPs. |
| Decision | `SendEligibility`: reason codes (`ALLOWED`, `IYS_REVOKED`, `IYS_UNKNOWN`, `IYS_UNAVAILABLE`, `LOCAL_SUPPRESSED`, `INVALID_EMAIL`, `MARKETPLACE_IDENTITY`, `IDENTITY_CONFLICT`, ...). RET always wins; doubt blocks. |

```sh
php artisan consent:build --tenant=demo                 # recipients, store flags, store history
printf '%s' '{"username":"…","password":"…"}' | php artisan iys:connect --tenant=demo \
    --iys-code=… --brand-code=… --environment=sandbox --secret-stdin
php artisan consent:sync --tenant=demo --kind=iys_test
php artisan consent:sync --tenant=demo --kind=iys_status --inline
php artisan consent:sync --tenant=demo --kind=iys_resolve --inline
php artisan consent:sync --tenant=demo --kind=iys_changes --inline
php artisan consent:report --tenant=demo
```

API: `GET /api/v1/consent/overview`, `GET /api/v1/consent/reconciliation`, `GET /api/v1/consent/conflicts`, `GET /api/v1/customers/{id}/eligibility`.

## Push (F3, OneSignal)

Read-only OneSignal sync into `push_profiles` / `push_subscriptions`, trusted-identifier
identity resolution, push eligibility separate from e-mail/SMS consent, and guarded test pushes
(`PUSH_SENDING_MODE` off by default, test allowlist, `send_push` scope). See
[docs/production/onesignal.md](docs/production/onesignal.md).

## E-mail (F4, Amazon SES)

Provider-independent delivery core (`EmailProvider`, capabilities, canonical messages and events)
with Amazon SES as the first connector: guarded sending (`EMAIL_SENDING_MODE` off by default, test
allowlist, `send_email` scope, Consent Engine for marketing, suppressions, quota and reputation
guards), idempotent queue, SNS event ingestion with signature verification, bounce/complaint
suppression, signed one-click unsubscribe, safe templates and campaign snapshots. See
[docs/production/ses.md](docs/production/ses.md).

## Automations and segments (F5)

Tenant segments (whitelisted JSON rule DSL compiled to parameterized SQL, read-only previews,
immutable snapshots) and an automation engine (versioned workflows, event and time triggers,
persistent delays, atomic claims, database-enforced idempotency). Email/push steps only record
planned actions after re-checking consent, suppression and frequency at action time; nothing is
sent. See [docs/production/automation.md](docs/production/automation.md).

## Campaigns, content and lead capture (F6)

Campaigns on F5 segments/snapshots with immutable F4 template versions (sanitized HTML, safe
variables, preview = send pipeline), a single readiness service with machine-readable blockers
(no dispatcher yet), a public lead/newsletter API (`/public/v1/forms/{id}/subscribe`: per-form
origins, honeypot, rate limits, idempotent, no enumeration, store-side consent only) feeding the
F5 `newsletter_subscribed` trigger, and a local commerce relink. See
[docs/production/campaigns-and-leads.md](docs/production/campaigns-and-leads.md) and
[docs/production/onesignal-identity-remediation.md](docs/production/onesignal-identity-remediation.md).

## Marketing operations panel (F7)

A server-rendered panel at `/` → `/app` (session login, CSRF, nonce CSP, role-based
navigation) for campaigns, segments, templates, automations, lead forms, approvals,
dispatch, integrations, readiness, suppressions and settings. Campaign approvals bind
to the frozen campaign (two-person rule, creator/requester never approve, any change
invalidates); dispatch is test-only to the allowlist, planned without provider calls,
idempotent, and guarded again before the provider. Live sending is hard-blocked. See
[docs/production/operations-panel.md](docs/production/operations-panel.md).

## Consent without the İYS API (F2-R)

A tenant consent strategy: `direct_api` (F2, default, unchanged) or `via_reconciliation`
(İYS ViA for acquisition and ViA Ret, plus İYS daily change reports), chosen only by an
operator (`consent:strategy`). The official İYS state is the latest of the İYS API, verified
ViA events and applied change reports; RET wins ties, and local unsubscribes and suppressions
are never lifted by it. Reports are uploaded under İYS › Mutabakat: dry-run, then apply once
(checksum, duplicate and conflict handling, no addresses stored). No ViA event format and no
İYS report format are implemented until official documentation or samples exist, so both stay
"not configured" and eligibility stays fail-closed. See
[docs/production/consent-via-reconciliation.md](docs/production/consent-via-reconciliation.md).

## Public website and CMS (F7.5)

`/` is the PratikTools company website (services, solutions/landing pages, projects, blog,
about, contact), server-rendered without session or cookies; the SaaS panel stays under `/app`.
Content lives in platform-level `site_*` tables (no tenant data) and is managed in
`/app › Web Sitesi` by the site-owner tenant (`SITE_OWNER_TENANT`) with separate edit/publish
permissions. Inquiries reuse F6 lead capture in that tenant. Includes SEO meta, canonical,
Open Graph, sitemap, schema.org JSON-LD, a managed robots.txt (OAI-SearchBot allowed, GPTBot a
setting) and consent-gated analytics by validated provider ids. See
[docs/production/public-website.md](docs/production/public-website.md).

## Production (Laravel Cloud)

See [docs/production/laravel-cloud.md](docs/production/laravel-cloud.md): boot safety rules, the
database role bootstrap (`scripts/production/bootstrap-db-roles.sh`), `php artisan db:verify-roles`,
deploy settings, Horizon sizing and scale-to-zero assumptions.

Static egress IP: provider allowlists (IYS, optionally OneSignal) require all
outbound calls to leave through fixed IPs. Deploy workers behind a NAT gateway
with reserved IPs and list them in `EGRESS_STATIC_IPS`.

## Local development

Requirements: Docker, PHP 8.4 with `pdo_pgsql` and `pcntl`, Composer.

```sh
cp .env.example .env                    # before composer install
composer install
php artisan key:generate
php artisan vault:generate-local-keys   # paste the three values into .env

docker compose up -d postgres redis     # creates saas_owner / saas_app / saas_platform
composer migrate:owner                  # migrations always run as the owner role

php artisan tenant:create "Demo Store" demo --owner-email=owner@demo.test
php artisan platform:admin:create admin@example.test "Platform Admin"

docker compose up -d app horizon scheduler
```

- API: `http://localhost:8000/api/v1/...` (log in with `POST /api/v1/auth/login` and `email`, `password`, `tenant` slug)
- Super Admin: `http://admin.saas.localhost:8000` (two-factor setup on first login)
- Horizon: `http://admin.saas.localhost:8000/horizon`
- Health: `http://localhost:8000/health`

`php artisan migrate` without `--database=pgsql_owner` fails on purpose: the runtime role cannot change the schema.

## Tests and CI

Tests use the real PostgreSQL schema (with RLS) and Redis from `docker compose`.

```sh
composer ci                 # pint, larastan, full test suite
composer test:isolation     # tenant isolation suite only
```

GitHub Actions (`.github/workflows/ci.yml`) runs dependency audit, Pint,
Larastan, a migration up/down/up cycle, unit, tenant isolation and feature
tests. Configure branch protection on `main` to require the `test` job.

## Environments

| File | Purpose |
| --- | --- |
| `.env.example` | local development (local KEK, debug on) |
| `phpunit.xml` | test defaults with test-only keys |
| `.env.staging.example`, `.env.production.example` | templates; values come from the deployment secret manager |

Staging and production refuse to boot with `APP_DEBUG=true`, insecure cookies,
missing keys or the local KEK driver (`App\Support\ProductionGuard`).
