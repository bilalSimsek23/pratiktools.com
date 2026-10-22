# Marketing Automation SaaS

Multi-tenant marketing automation platform for Ticimax stores. This repository
is independent of the IGS admin application; IGS is the first tenant.

Current phase: **F0 – platform foundation** (tenancy, row-level security,
secret vault, audit log, queues, health, CI). No provider connectors yet.

## Architecture in one page

| Concern | How |
| --- | --- |
| Tenant isolation (database) | PostgreSQL row-level security with `FORCE ROW LEVEL SECURITY` on every tenant table. Policies compare `tenant_id` with `app_current_tenant()`, which reads the session setting `app.tenant_id`. No context = no rows, and writes fail. |
| Database roles | `saas_owner` owns the schema and runs migrations only. `saas_app` is the runtime role (not owner, `NOBYPASSRLS`). `saas_platform` serves the Super Admin panel with column-level grants and no access to personal data or secrets. |
| Tenant isolation (application) | `BelongsToTenant` global scope fails closed (`TenantContextMissing`), rejects writes for another tenant, and route model binding only resolves records of the current tenant. |
| Tenant context | `App\Tenancy\TenantContext`. Set from the tenant an API token was issued for (never from request input), from a job's `tenant_id`, from a console `--tenant` option or from a webhook URL. Mirrored into PostgreSQL on every connection, cleared after each request and job. |
| Secrets | `SecretVault`: per-tenant data key (DEK), AES-256-GCM, wrapped by a KEK behind `KeyEncryptionService` (local adapter for dev/test; KMS adapter required for production). Ciphertext is bound to tenant + integration. Secrets are write-only in the API and every decrypt is audited. |
| Audit | `audit_logs` is append-only: no UPDATE/DELETE grants, a trigger, and a model guard. IP and user agent are stored as HMACs. |
| Queues | Redis + Horizon. Queues: `system`, `webhooks`, `sync`, `automation`, `send`. Tenant jobs use `TenantAwareJob` and carry `tenant_id`; a queue guard clears context around every job. |
| Rate limiting | Redis token buckets per `tenant:{tenant_id}:{provider}` plus optional platform-wide buckets (e.g. IYS per egress IP), consumed atomically. |
| Webhooks | `POST /webhooks/{provider}/{tenantPublicId}` stores into `webhook_inbox` (idempotent on `external_event_id`), then processes on the `webhooks` queue. |
| Super Admin | Separate host (`ADMIN_DOMAIN`), separate guard, mandatory TOTP 2FA, aggregate data only. Horizon is only reachable there. |
| Observability | JSON logs with `tenant_id`, `request_id`, `job_id`, `provider`, `run_id`, `error_class`; e-mails, phone numbers and secrets are redacted. `/healthz` (liveness), `/health` (database, Redis, Horizon, queue backlog). `ErrorReporter` / `Tracer` interfaces for Sentry/OpenTelemetry. |

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
