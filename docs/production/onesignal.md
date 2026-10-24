# OneSignal (push) rollout

F3 scope: read-only connection and sync, push identity and eligibility,
guarded test-push infrastructure. **No push to the general audience.**

## API facts used (OneSignal REST API reference, checked 2026-10)

| Need | Endpoint | Notes |
| --- | --- | --- |
| Auth | `Authorization: Key <App API key>` | App API key per app; Organization key not needed. App ID is public. |
| App check / counts | `GET /apps/{app_id}` | `players`, `messageable_players`. |
| Bulk read | `POST /players/csv_export?app_id=` | Gzip CSV, ~2,000 records/s, URL 404s until ready, kept 3 days, one export per account at a time. `identifier` column = push token / e-mail / phone: never stored. |
| Single user | `GET /apps/{app_id}/users/by/{alias_label}/{alias_id}` | Not used for bulk. |
| Send | `POST /notifications?c=push` | `include_subscription_ids` (≤20,000), `idempotency_key` (UUID, 30 days): same key on every retry. Empty `id` = not sent. |
| Limits | | View endpoints 1 req/s/app; create message 150/s (free) or 6,000/s (paid); 429 with `Retry-After`; at most 10 × subscribed messages per rolling 15 min or the app is disabled. |
| Legacy | `GET /api/v1/players` | Legacy key, max 80,000 records: not used. |

Delta: OneSignal has no change feed for subscription status
(`last_active_since` does not see unsubscribes), so the sync is a full
export each run. Server-side webhooks for subscription changes do not exist
(web push webhooks are browser-side; Event Streams are a separate paid
feature): none is implemented.

## Data and identity

- `push_profiles` (OneSignal user, external id, resolved customer),
  `push_subscriptions` (subscription id, platform, status, device, sessions),
  `push_identity_mappings`, `push_suppressions`, `test_recipients`,
  `push_send_requests`. All FORCE RLS; the Super Admin role has no access.
- Status from `notification_types`: ≥1 subscribed; 0/-99 never subscribed;
  other negatives unsubscribed.
- Identity: mapping table first, then the integration's
  `external_id_strategy` (`none` default, `ticimax_member_id`, `email`
  guarded against marketplace relays and shared addresses). Phone numbers
  are never used; customers are never created or merged.
- For our own mobile login flow later: `PushIdentityResolver::platformExternalId()`
  issues `ptc_` + 24 random characters per customer (opaque, stable).
- Push eligibility is separate from e-mail/SMS consent: subscribed, present
  at the provider, not suppressed.

## Sending safety

- Effective mode = stricter of `PUSH_SENDING_MODE` (`off` | `test_only` |
  `live`, default `off`) and the tenant `sending_mode` (`off` → off,
  `internal`/`pilot` → test_only, `live` → live).
- `live` is refused in F3. `test_only` sends only to active
  `test_recipients`, at most 5 per request, each eligible.
- The integration needs the `send_push` scope (`php artisan onesignal scope --grant=send_push`).
- The push client is not even built with the platform mode off, re-checks
  the mode and the recipients itself, and needs a guard-issued
  authorization.

## Commands

```sh
printf '%s' "$ONESIGNAL_APP_API_KEY" | php artisan onesignal:connect --tenant=igs --app-id=<APP_ID> --secret-stdin
php artisan onesignal check --tenant=igs      # read-only, sets connected
php artisan onesignal sync --tenant=igs --inline
php artisan onesignal report --tenant=igs
php artisan onesignal strategy --tenant=igs --strategy=ticimax_member_id
php artisan push:test-recipient add --tenant=igs --subscription=<id> --label="QA iPhone" --approved-by="<name>"
php artisan onesignal scope --tenant=igs --grant=send_push
# only with PUSH_SENDING_MODE=test_only and tenant sending_mode=internal:
php artisan onesignal:test-push --tenant=igs --recipient=<id> --confirm="SEND REAL TEST PUSH"
```

## Production rollout (each step needs explicit approval)

1. Deploy with `PUSH_SENDING_MODE=off`, `ONESIGNAL_SYNC_SCHEDULE_ENABLED=false`.
2. `onesignal:connect`, `onesignal check`, `onesignal sync`, `onesignal report`.
3. Decide the external id strategy from the report.
4. Test push: add the test device, grant `send_push`, set
   `PUSH_SENDING_MODE=test_only` and tenant `sending_mode=internal`,
   redeploy, send, then set both back to off.
5. Enable the nightly sync (`ONESIGNAL_SYNC_SCHEDULE_ENABLED=true`).
