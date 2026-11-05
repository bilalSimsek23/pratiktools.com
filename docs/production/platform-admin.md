# Platform Yönetimi (Super Admin) — tenant management

Served only on `ADMIN_DOMAIN` (routes/admin.php), guard `platform`
(`platform_admins`, separate from tenant users), TOTP second factor on every
session, and a password re-confirmation (`PLATFORM_REAUTH_SECONDS`, default
900) before any tenant change. Tenant users (owner, admin, marketer,
approver, viewer) cannot reach it: their session has no `platform` guard and
the admin routes do not exist on the panel host.

Menu: **Genel Bakış**, **Müşteriler**, **Sistem Durumu**. Customer detail tabs:
Genel, Kullanıcılar, Entegrasyonlar, Kullanım, Audit.

## Database access

| What | Connection / role | Notes |
|---|---|---|
| Lists, details, counters, audit trail | `pgsql_platform` / `saas_platform` | Column grants only; no secret, setting or personal table |
| Owner / member names and e-mails | `app_platform_tenant_owners()`, `app_platform_tenant_members(uuid)` | SECURITY DEFINER, platform role only; `db:verify-roles` fails if the platform role can run any other definer function (besides `app_kek_inventory`) |
| Platform notes | `platform_tenant_notes` on `pgsql_platform` | FORCE RLS, policy for the platform role only; `saas_app` has no grant (verified by `db:verify-roles`). Audited without the text; values that look like secrets are refused |
| Create tenant, edit basic data, ViA configuration, invite, role, disable, suspend | `pgsql` / `saas_app` inside `TenantContext::run($tenantId)` | FORCE RLS applies; the tenant id is the URL of an authenticated 2FA admin, checked to exist |

No `/app` request uses the platform connection; the platform connection is
never chosen from request input.

## Provisioning (shared by `tenant:create` and Yeni Müşteri)

`App\Tenancy\TenantProvisioner::provision()` — one transaction in the new
tenant's context: tenant → settings (sending mode off) → owner user (created
or reused) → owner membership → data key → audit `tenant.created` → owner
invitation (`tenant.owner.invited`). Any failure rolls everything back.
Re-running with the same slug and owner returns the existing tenant and
writes nothing; a slug of another owner is refused. Slugs: lowercase letters,
digits and single hyphens, 2–63 chars, reserved words refused. It never
changes the website owner, never creates integrations (all start "not
configured") and never enables sending.

CLI: `php artisan tenant:create "<name>" <slug> --owner-email=<e> --owner-name="<n>" --invite`
(add `--show-invite-link` to print the one-time link; without `--invite` a
hidden prompt may set an operator password, which activates directly).

## Invitations

- Token: 64 random characters; only `sha256` is stored (`user_invitations`,
  tenant data under FORCE RLS). Single use (row lock, `accepted_at`),
  expires after `PLATFORM_INVITATION_TTL_HOURS` (72), revoked when a new link
  is issued or the membership is disabled.
- Link: `APP_URL/app/davet/<token>?expires=…&signature=…` (relative signed
  route). Tampered or expired signatures are refused before any lookup;
  every invalid/used/revoked link gets the same 410 page.
- New account (`users.activated_at` null): the person chooses their own
  password (≥ 12) and is signed in to the tenant. Until then the account
  cannot sign in at all.
- Existing account: must sign in as itself to accept; the link alone never
  sets or changes a password.
- Delivery is manual: e-mail is off, so the link is shown once to the Super
  Admin (session flash; never logged or audited). `email_verified_at` is set
  only for e-mail delivery (not available yet).

## Suspension

`App\Platform\TenantLifecycle` sets `status = suspended` (+ time, reason) or
back to `active`; audited `tenant.suspended` / `tenant.reactivated`. Data is
never deleted; there is no tenant delete. Already enforced by status checks:
panel (403), API tokens (403), queued jobs (skipped), scheduler fan-out and
console `--tenant` / webhook lookups (active tenants only). The website owner
tenant cannot be suspended.

## Members

Super Admin: invite (any `TenantRole`), change role, disable / enable, new
link. Tenant panel (`/app/team`, Ayarlar › Ekip, `members.manage`, recent
password): owner gives admin/marketer/approver/viewer, admin gives
marketer/approver/viewer; nobody creates or changes an owner or themselves
there; marketer/approver/viewer cannot manage. The tenant is the session
membership, never request input; foreign membership ids answer 404.
A tenant always keeps one active owner (no membership delete; the last
owner cannot be disabled or demoted). Ownership transfer: Super Admin only
(extension point for a self-service transfer). A suspended tenant gets no
new invitation and none can be accepted. Enabling never activates an
invitation nobody accepted. Audit: `tenant.user.invited`,
`tenant.owner.invited`, `tenant.role.changed`, `tenant.membership.disabled`,
`tenant.membership.enabled`, `tenant.invitation.accepted`. No token,
password or secret is ever in audit metadata.

## SECURITY DEFINER functions

All: `SET search_path = pg_catalog, public`, schema-qualified static SQL,
`REVOKE ALL … FROM PUBLIC`, one explicit `GRANT EXECUTE`; checked by
`db:verify-roles`.

| Function | Why definer | EXECUTE | Reads | Input |
|---|---|---|---|---|
| `app_invitation_tenant(text)` | the link is opened before any tenant context; RLS hides invitations | `saas_app` | `user_invitations.tenant_id` of an open (not accepted/revoked/expired) invitation | sha256 of the token (computed server-side from a 64-char token) |
| `app_platform_tenant_members(uuid)` | the platform role has no grant on `users` | `saas_platform` | `tenant_users` (id, role, status, created_at) + `users` (id, name, email, activated) of one tenant | tenant uuid from the admin URL (typed `uuid`) |
| `app_platform_tenant_owners()` | same | `saas_platform` | owner memberships + `users` (name, email) | none |

The owner role reads these rows through SELECT-only `owner_directory`
policies.

## Not included (by design)

- **Impersonation**: not implemented. A safe version needs a read-only
  panel mode enforced for every route, a cross-host one-time session hand-off
  and access to customer personal data by Super Admins, which the platform
  role deliberately does not have. Break-glass grants exist but no data path
  honours them.
- **Plans / billing**: `tenants.plan` is an unused extension point (null).

## Editing a tenant (Genel › Temel bilgileri düzenle)

Name, code (slug), contact phone, country, timezone, currency and locale
(`App\Platform\TenantMetadata`, recent password, tenant's own context).
Never the id, public id, owner, status, plan or data key. Every relation,
job, session, API token and webhook uses the tenant id or public id; the
code is only an operator handle (`--tenant=<code>`). Changing it needs the
new code typed again; the old code becomes free; the website owner's code
cannot change here (the website finds its owner by code). Audit
`tenant.metadata_updated`: changed fields with old/new values, the contact
phone only as "changed".

## Production

1. Deploy runs `migrate --database=pgsql_owner --force --isolated`
   (`2026_11_08_000001_create_platform_tenant_management`,
   `2026_11_09_000001_add_platform_admin_activation`).
2. `ADMIN_DOMAIN` must point at the app with its own hostname.
3. First Super Admin (Laravel Cloud Commands, no interactive terminal):
   `php artisan platform:admin:invite <email> "<name>"` — see below. With an
   interactive terminal `platform:admin:create <email> "<name>"` (hidden
   password prompt) also works.
4. `php artisan db:verify-roles` must pass.

## Super Admin activation links

`platform:admin:invite {email} {name} {--ttl=30}` (5–120 minutes):

- Creates the admin as `invited` with an unusable password (owner
  connection), or — for an account that is still `invited` — issues a new
  link; the previous link stops working. An `active` or `disabled` account
  is refused.
- Prints the link once: `https://ADMIN_DOMAIN/activate/<token>?expires=…&signature=…`.
  The token is 64 random characters; only its SHA-256 is stored
  (`platform_admins.activation_token_hash`, with `activation_expires_at`).
  Audit `platform.admin.invited` records only `reissued` and the TTL.
- The link opens a form on the admin host where the admin chooses a
  password (≥ 16 characters, confirmed). The password is hashed in PHP and
  stored by `app_platform_admin_activate(token_hash, password_hash)`:
  SECURITY DEFINER owned by `saas_owner`, `search_path = pg_catalog, public`,
  EXECUTE for the platform role only. It changes only an `invited`,
  unexpired row with that token, accepts only a bcrypt/argon2 hash, sets
  `active` and clears the token. The platform role still has no UPDATE on
  `password` or `status`.
- Then the admin is signed in (`platform.admin.activated`,
  `platform.login` via activation) and sent to two-factor setup; no Super
  Admin page opens before it is completed (`EnsurePlatformTwoFactor`).
- A bad signature and an invalid, used, replaced or expired token all get
  the same 410 page. Opening and submitting are rate limited per IP
  (`admin-activation`, 10/min). An `invited` account cannot sign in.
- The link appears in the command output (Cloud keeps command history):
  open it right away; once used or expired it is worthless.
- `platform:admin:create` records `platform.admin.created`.
