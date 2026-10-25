# Automation engine and segmentation (F5)

Automations decide **who should get what, when**, and record it as a
**planned action** (intent). They never send: no provider client is reachable
from the engine, no `SendEmail`/`SendPush` job is dispatched, nothing is
written to İYS or Ticimax. A later dispatcher (F6+) will turn planned actions
into messages through the F3/F4 guards.

## Data model

| Table | Purpose |
| --- | --- |
| `segments` | tenant segments: `dynamic` (evaluated at use) or `snapshot` |
| `segment_snapshots`, `segment_snapshot_members` | frozen members; the app role has INSERT/SELECT only (immutable) |
| `automations` | name, template, state (draft → published ⇄ paused → archived) |
| `automation_versions` | trigger, steps, settings; a published version is immutable (DB trigger) |
| `automation_runs` | one run per subject: status, current step, `resume_at`, claim token/lease, exit reason |
| `automation_run_steps` | one row per (run, step): outcome and reason |
| `automation_planned_actions` | email/push intents: channel, message type, recipient ref, references only |
| `automation_trigger_cursors` | per-tenant read position in each trigger source |
| `automation_metrics_daily` | counters per automation/day (the platform role may read them; no PII) |

All tables: `tenant_id`, FORCE RLS, app role grants, owner-run migration;
`db:verify-roles` checks them.

Idempotency is enforced by the database:

- `automation_runs (tenant, automation, idempotency_key)` unique, key =
  sha256(automation | trigger type | trigger reference);
- `automation_runs_one_active` partial unique index: one pending/running/
  waiting run per (automation, scope) — scope is the customer, or the cart for
  abandoned carts (a newer cart state supersedes the older waiting run);
- `automation_planned_actions (tenant, idempotency_key)` unique, key =
  sha256(run | step);
- `automation_run_steps (tenant, run, step_key)` unique.

## Triggers

Event based, read from existing tables after a per-tenant cursor (uuid7 ids
are time ordered; bounded batches):

| Trigger | Source |
| --- | --- |
| `cart_abandoned` | `customer_events` CART_UPDATED (item_count > 0) |
| `order_created` | ORDER_PLACED |
| `order_completed` | ORDER_STATUS_CHANGED to `automation.completed_order_status_codes` (Ticimax 7 "Teslim Edildi") |
| `customer_created` / `newsletter_subscribed` | CUSTOMER_CREATED (member / subscriber) |
| `consent_changed` | `consent_events` (address linked to exactly one customer) |
| `push_subscription_changed` | `push_subscriptions` changes of resolved push profiles |

Time based, evaluated in SQL at most every `time_trigger_interval_minutes`:
`no_purchase_for {days, catch_up_days}` (one run per inactivity episode,
keyed by the last order date) and `segment_periodic {segment_id,
reentry_days}`. "X days after an order" or "X minutes after cart activity" are
event triggers followed by a delay step.

Never started: backfilled events, events older than
`max_trigger_age_hours` or from before the version was published, and
subjects without a trusted customer (anonymous carts, addresses shared by
several customers) — counted as `identity_blocked`.

## Steps

`delay {minutes, from: step|trigger}`, `condition {check: no_order_since_trigger |
cart_still_open | cart_items_available | customer_rule}`, `segment_check`,
`consent_check`, `frequency_check`, `create_email_intent`, `create_push_intent`,
`exit`. Intent steps take `message_type` (marketing | transactional) and
`on_ineligible` (continue | exit); `create_push_intent` can be
`only_if_no_intent` (e-mail first, push as fallback).

Delays never hold a worker: the run is stored as `waiting` with `resume_at`.
Each scheduler tick claims due runs with one `UPDATE … FROM (SELECT … FOR UPDATE
SKIP LOCKED)`, giving every claimed run its own claim token and lease; all
writes of an advance happen in one transaction and only if the token still
matches. A crashed worker's run is reclaimed after the lease.

## Safety at action time

Entering an automation is not permission. Right before an intent is created:

- e-mail marketing: `SendEligibility` (İYS ONAY required; unknown,
  unavailable or stale İYS = blocked — F2 fail-closed), suppressions, the
  shared marketing frequency cap (`email.guardrails.frequency_cap`, counting
  sent marketing mail and planned marketing e-mail);
- e-mail transactional: valid address without an address-level suppression;
  no marketing consent, never capped, never counted;
- push: a subscription of a push profile resolved to the customer, subscribed
  and not suppressed; marketing push capped (`automation.frequency_cap.push`).

Abandoned cart: wait → exit if the customer ordered or the cart is gone/changed/
no longer seen → only items active and in stock **now** → e-mail intent, else
push intent. Prices and stock are never copied into the intent; it carries
references (`cart_id`, `product_ids`, recommendation context) and
`resolve_at_dispatch: [price, stock, content, consent]`. Cart items not yet
linked to the catalog are resolved by external id.

## Segments

JSON DSL: `{"all": […]}`, `{"any": […]}`, `{"not": node}`, leaves
`{"field", "op", "value"}`. Whitelisted fields only (order_count, total_revenue,
average_order_value, last/first_order_at, last_cart_at, member_since, created_at,
last_interaction_at, is_guest, city, has_open_cart, newsletter_subscriber,
push_subscribed, email_marketing_eligible, email_consent_status,
purchased_category/product, cart_contains_category/product), typed operators,
depth ≤ 4, ≤ 25 rules, lists ≤ 100. Compiled to parameterized SQL; relationship
fields are semi-joins evaluated once. Preview and dry-run run in a `READ ONLY`
transaction with a statement timeout; iteration is keyset-paginated.

## Operations

```sh
php artisan automation:create abandoned_cart --tenant=igs --name="Sepet" --delay-minutes=120
php artisan automation:create winback --tenant=igs --name="Seni özledik" --days=90
php artisan automation:dry-run --tenant=igs --automation="Seni özledik" --days=30 --days=90
php artisan automation:state publish|pause|archive --tenant=igs --automation=<id|name>
php artisan automation:list|report|runs --tenant=igs [--automation=…]
php artisan automation:resume-due --tenant=igs         # one bounded tick (planned actions only)
php artisan automation:cancel-run --tenant=igs --run=<id> --reason="…"
php artisan segment preview --tenant=igs --rules='{"all":[{"field":"order_count","op":"gte","value":2}]}'
```

API (tenant token): `GET /api/v1/automations`, `GET /api/v1/automations/{id}`
(versions, last runs, metrics, exit reasons), `POST /api/v1/automations/{id}/dry-run`.
Permissions: `automations.view` (owner, admin, marketer), `automations.manage`
(owner, admin).

Scheduler: `AUTOMATION_SCHEDULE_ENABLED=false` by default. When enabled, one
`RunAutomationTick` per tenant per minute on the `automation` queue (never
`send`): unique per tenant, no overlap, bounded batch (1,000 events per source,
200 runs, 45 s), so a large tenant cannot starve others.

## Production rollout (each step needs explicit approval)

1. Deploy; migration through the standard procedure
   (`vault:verify-kek`, `migrate --database=pgsql_owner --force --isolated`).
   `AUTOMATION_SCHEDULE_ENABLED` stays false.
2. Create automations as drafts and dry-run them on real data.
3. Publish and enable the scheduler only when planned actions are wanted;
   they still send nothing until a dispatcher phase.
