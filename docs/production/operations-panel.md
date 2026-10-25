# Marketing operations panel, approval and controlled dispatch (F7)

F7 adds the chain **campaign preparation → readiness → approval → test-only
dispatch → observability** and a server-rendered panel for the marketing team.
Live sending does not exist in this phase: it is refused by a constant
(`DispatchGuard::LIVE_AVAILABLE = false`), by the sending-mode service and by
database checks. Nothing in the panel or the API can bypass it, including the
owner role.

## Panel

- `https://<host>/` redirects to `/app`: login without a session, dashboard with one.
- Session guard `web`, CSRF on every form, session regenerated at login, nonce
  CSP (no inline scripts or style attributes). The host check (`RejectAdminHost`)
  keeps the panel off the platform admin host.
- After login the user enters their only tenant or picks one; membership and
  tenant status are re-checked on every request (`PanelTenant`).
- Sensitive actions need a password confirmation within the last 10 minutes
  (`PanelRecentAuth`, `dispatch.reauth_seconds`): campaign transitions and
  reopen, approval decisions, template publish/archive, form activation and
  consent text, automation state, dispatch queueing, sending mode.
- Email previews are served from `/app/template-versions/{id}/frame` with
  sample values, sanitized HTML and a sandboxed CSP (`default-src 'none'; sandbox`).

| Screen | Path | Permission |
| --- | --- | --- |
| Dashboard | `/app` | tenant.view |
| Campaigns (list, steps, detail) | `/app/campaigns` | campaigns.view / manage / publish |
| Approvals | `/app/approvals` | campaigns.view, decisions campaigns.approve |
| Dispatch | `/app/dispatches` | campaigns.view, queue campaigns.test_dispatch |
| Templates, senders | `/app/templates`, `/app/senders` | campaigns.view, edit content.manage, publish content.publish |
| Segments (rule builder) | `/app/segments` | campaigns.view, save campaigns.manage |
| Automations | `/app/automations` | automations.view / manage |
| Lead forms, leads | `/app/forms`, `/app/leads` | leads.view, change forms.manage |
| Integrations | `/app/integrations` | integrations.view |
| Readiness | `/app/readiness` | campaigns.view |
| Suppressions | `/app/suppressions` | suppressions.view |
| Settings | `/app/settings` | tenant.view, change tenant.sending_mode |

Navigation only shows screens the role can open; the controllers enforce the
same permissions.

### Roles

| Role | Can |
| --- | --- |
| owner | everything, including the sending mode (OFF / TEST_ONLY only) |
| admin | everything except the sending mode |
| marketer | create and edit campaigns, content and segments; request approval and test dispatch plans |
| approver | view and decide approvals; cannot create or edit |
| viewer | dashboard only |

Members are managed by the operator with the CLI (passwords from a hidden
prompt or STDIN, never arguments; every change is audited):

```sh
php artisan tenant:member list --tenant=igs
php artisan tenant:member add --tenant=igs --email=... --name="..." --role=marketer
php artisan tenant:member role --tenant=igs --email=... --role=approver
php artisan tenant:member disable --tenant=igs --email=...
```

## Approval

- Requesting approval needs a **ready** campaign: content, audience snapshot
  and sender are frozen. An approval binds to the campaign fingerprint
  (snapshot, content version, sender, integration, audience, type).
- Two slots (review, approve). Test-only dispatch needs 1 valid approval, live
  would need 2 (`config/dispatch.php`).
- The creator and the requester can never approve; the same person cannot
  fill both slots (also a database check: `decided_by <> requested_by`). A
  rejection needs a reason. Approvals expire after 7 days.
- Changing content, audience or sender requires **reopen** (ready → draft),
  which invalidates every approval and cancels open dispatch requests.
- Approval rows are immutable once decided (trigger); every step is audited and
  approvers get an in-app notification (no e-mail or push).

## Test-only dispatch

1. The requester picks recipients from the tenant's test allowlist
   (`test_recipients` ids; free-typed addresses are impossible).
2. A dispatch request stores an immutable plan computed without any provider
   call: recipients evaluated/eligible/blocked with reasons, provider, sender,
   estimated calls and duration, SES quota (sandbox, 24 h limit, after dispatch),
   the sending mode at plan time, `provider_calls_made: 0`.
3. One request per tenant + snapshot + channel + mode + generation (unique
   index). Repeating the request returns the same row; a new dispatch needs the
   explicit "new dispatch" action (next generation).
4. Queueing (`campaigns.test_dispatch`, recent password) and execution re-run
   the guard: test_only mode, campaign still frozen and unchanged, approvals
   valid, recipients still allowlisted, sender verified, provider connected
   with the send scope, **effective sending mode TEST_ONLY**. While the mode is
   OFF the plan can be created but not queued.
5. Execution reuses the F4/F3 pipelines (`EmailDelivery`, `PushDelivery`) with a
   per-recipient idempotency key and a `[TEST]` subject prefix; messages carry
   `dispatch_id` for observability. Cancelled and completed requests are final.

## Readiness and external blockers

Readiness rows show status, reason code, explanation and the operator action
(`ReadinessCatalog`). The external blockers are derived from stored state, never
from flags:

| Blocker | Source |
| --- | --- |
| IYS_API_ACCESS | İYS integration and a fresh successful status sync |
| SES_PRODUCTION_ACCESS | SES account check (`production_access`) |
| EMAIL_UNSUBSCRIBE_KEY | `EMAIL_UNSUBSCRIBE_KEY` present — action: *Add EMAIL_UNSUBSCRIBE_KEY as Laravel Cloud Secret.* |
| SES / OneSignal / Ticimax vault connection | integration connected with a vault secret |
| ONESIGNAL_EXTERNAL_ID | `External ID coverage: x / y` — the Ticimax native app must identify logged-in users to OneSignal |

The integrations screen shows connection, health, last sync, capability,
blocker and required action per provider, and only whether a secret is in the
vault (never the secret).

## Sending mode

The panel and `PATCH /api/v1/tenant/settings` accept only `off` and `internal`
(TEST_ONLY). Anything else is refused with *Live sending is disabled until
external readiness requirements are satisfied.* and audited
(`tenant.sending_mode_change_refused`). The platform limits
(`EMAIL_SENDING_MODE`, `PUSH_SENDING_MODE`) stay operator-only.

## Operator checklist before a real test send

Not done by the application: one test e-mail to a verified SES sandbox
recipient and/or one test push to an allowlisted subscription requires the
operator to (1) add the recipient to the allowlist, (2) set the platform and
tenant sending mode to TEST_ONLY, (3) get one approval from a second person,
(4) queue the dispatch, and (5) return the modes to OFF.
