# Laravel Cloud production runbook

Target: Laravel Cloud, EU Central (Frankfurt), Laravel Serverless Postgres 17
(Neon), Laravel Valkey. Nothing in this document is automated; each step is
done by an operator.

## Safety rules enforced by the application

`App\Support\ProductionGuard` refuses to boot (every request and every
artisan command except build-time ones) when:

| Rule | Settings |
| --- | --- |
| Debug off, secure and HTTP-only session cookies | `APP_DEBUG`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY` |
| Keys present | `APP_KEY`, `SECRET_VAULT_FINGERPRINT_KEY`, `AUDIT_HASH_KEY` |
| KEK driver `cloud_secret` (or optional `aws_kms`); local, empty, unknown or unimplemented drivers refused | `SECRET_VAULT_KEK_DRIVER` |
| Platform KEK: base64 of 32 random bytes, not a known test key, not equal to `APP_KEY`, `AUDIT_HASH_KEY`, the fingerprint key or a previous KEK; at most 3 previous KEKs | `SECRET_VAULT_KEK`, `SECRET_VAULT_KEK_PREVIOUS` |
| Optional AWS KMS complete: key ARN (no alias) in the configured region, credentials set, SDK installed | `SECRET_VAULT_AWS_*` |
| Runtime connection is the app role, the three roles differ | `DB_USERNAME`, `DB_OWNER_USERNAME`, `DB_PLATFORM_USERNAME` |
| No URL-configured runtime connection (`DB_URL` is ignored anyway) | — |
| No transaction pooler (tenant context is a session setting) | `DB_HOST` without `-pooler` |
| TLS to Postgres | `DB_SSLMODE=require` (or `verify-full`) |
| İYS production only with static egress IPs | `IYS_ALLOW_PRODUCTION`, `EGRESS_STATIC_IPS` |

## Database roles

| Connection | Role | Used by |
| --- | --- | --- |
| `pgsql` | `saas_app` (LOGIN, NOBYPASSRLS) | web, queue, scheduler; RLS applies |
| `pgsql_owner` | `saas_owner` (LOGIN, NOBYPASSRLS) | migrations only; owns every object |
| `pgsql_platform` | `saas_platform` (LOGIN, NOBYPASSRLS) | Super Admin; column grants, no tenant PII |

The credential Laravel Cloud injects (`DB_USERNAME`/`DB_PASSWORD`, a
`neon_superuser` member with BYPASSRLS) is used **only** to run
`scripts/production/bootstrap-db-roles.sh` once. Custom environment
variables override the injected ones, so the runtime never sees it.

## Order of operations

1. **Platform KEK**: on a trusted workstation run `php artisan vault:generate-kek`;
   store the key in the offline escrow and as the Laravel Cloud Secret
   `SECRET_VAULT_KEK` (see [secret-vault-rotation.md](secret-vault-rotation.md)).
2. **Generate other secrets** locally: `APP_KEY` (`php artisan key:generate --show`),
   `SECRET_VAULT_FINGERPRINT_KEY`, `AUDIT_HASH_KEY` (`openssl rand -base64 32`),
   three role passwords (`openssl rand -base64 36`). All as Cloud Secrets.
3. **Bootstrap roles** from a trusted machine with psql 15+, using the
   database's administrator credential and the **direct** host:
   ```sh
   export PGHOST=<direct host> PGDATABASE=<db> PGUSER=<admin> PGPASSWORD=<admin password>
   export OWNER_PASSWORD=... APP_PASSWORD=... PLATFORM_PASSWORD=...
   sh scripts/production/bootstrap-db-roles.sh
   ```
   It is idempotent; the output must show all flags `f` and `OK`.
4. **Cloud Secrets / environment variables**: see `.env.production.example`.
   Add `DB_USERNAME=saas_app` and `DB_PASSWORD` as custom variables so they
   override the injected administrator. If Cloud injects `DB_URL` or
   `DATABASE_URL`, it is ignored by the runtime connection.
5. **Cloud deployment settings**
   - Build: `composer install --no-dev --optimize-autoloader --no-interaction`
   - Deploy commands, in this order:
     ```
     php artisan vault:verify-kek
     php artisan migrate --database=pgsql_owner --force --isolated
     ```
     `vault:verify-kek` stops the deploy if a configured KEK does not open
     the data keys in use (an empty database passes as bootstrap). Replace
     Cloud's default `php artisan migrate --force`, which runs as the runtime
     role and fails by design.
   - Do not add `queue:restart`, `horizon:terminate`, `optimize:clear` or
     `storage:link`.
6. **First deploy** with Horizon and Scheduler still off.
7. **Verify** (Cloud "Commands" tab):
   - `php artisan db:verify-roles`: every line PASS.
   - `php artisan vault:verify-kek`: PASS.
   - `GET /healthz` 200. `GET /health`: `database.role=saas_app`,
     `bypass_rls=false`, `vault.status=ok`; `queue` fails until Horizon runs.
   - Log in to the admin host, open `/diagnostics/request`: `secure=true`,
     `client_ip` is your own address, `from_trusted_proxy=true`.
8. **Worker cluster**: always awake (not following the App cluster), one
   background process `php artisan horizon`. Enable the **Scheduler** on this
   cluster only. Redeploy.
9. Set `HEALTH_REQUIRE_HEARTBEAT=true`, redeploy; `/health` must be 200 with
   `scheduler.status=ok` and `queue.horizon=running`.

## Queues and Horizon

- Queues: `system`, `webhooks` (supervisor-platform), `sync`, `automation`,
  `send` (supervisor-work).
- Pilot sizing (production): platform 1–2 processes, work 1–3 processes
  (about 5 PHP processes, 1–2 GB worker instance).
- `retry_after` 960 s; every job timeout ≤ 900 s (tested). Sync jobs work in
  45 s slices and hold an overlap lock, so a duplicate delivery never calls
  Ticimax or İYS twice.

## Scale-to-zero (production)

| Resource | Setting | Why |
| --- | --- | --- |
| App cluster | off | the scheduler would wake it every minute anyway; webhooks must answer at once |
| Worker cluster | off (always awake) | queued jobs do not wake a sleeping environment; Horizon must run |
| Valkey | off (Pro size), eviction `noeviction` or `volatile-lru` | holds queues, locks, sessions, tokens, rate limits; `allkeys-lru` could evict jobs |
| Postgres | off, or a long idle timeout | wakes on demand; the tenant context is re-applied after reconnects (tested), but the first query is slower |

The application does not depend on any of them sleeping.

## Logging

`LOG_CHANNEL=json_stderr` (the production default). Structured JSON with
redaction. Enabling Nightwatch makes Cloud set `LOG_CHANNEL=stack` with
`laravel-cloud-socket`; that path bypasses our redaction tap, so add the tap
to that stack before enabling Nightwatch logs.

## Secret vault

Production KEK driver is `cloud_secret` (Laravel Cloud Secret). AWS KMS
stays available as an optional driver (`composer require aws/aws-sdk-php`,
`SECRET_VAULT_KEK_DRIVER=aws_kms`); the cloud secret keyring then stays
configured for unwrapping until `vault:rewrap --all` has moved every data
key. Rotation, compromise response, escrow and the Laravel Cloud Commands
risk: [secret-vault-rotation.md](secret-vault-rotation.md).

## İYS

`IYS_ALLOW_PRODUCTION=false`. Laravel Cloud's outbound IPs are shared and
regional; customer-specific static IPs exist only on Laravel Private Cloud.
İYS production needs registered static IPs (the guard refuses otherwise).
