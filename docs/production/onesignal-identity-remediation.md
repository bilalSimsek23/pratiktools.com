# OneSignal identity remediation plan (IGS)

## What we know (measured, F3/F5/F6)

- ~9.7k push subscriptions (iOS ~4.5k, Android ~5.3k; ~4.1k subscribed), all
  without an `external_id`; no tags. Every push profile is `no_external_id`, so
  no subscription can be tied to a customer and automations plan no push.
- The IGS admin codebase contains no OneSignal integration; the app is the
  Ticimax native mobile app, whose OneSignal SDK calls are not visible to us.

## What we do not know (must be confirmed with Ticimax, not assumed)

1. Whether the Ticimax app calls `OneSignal.login(<id>)` / `logout()` at all, and
   whether that is configurable per store.
2. Which identifier the app has after login (Ticimax member id `UyeID`, e-mail,
   an internal token).
3. Whether identity verification (JWT) can be enabled, i.e. whether a server
   can issue the token the app passes to `login`.
4. Whether the app is built per store (own OneSignal app) or shared.

## Target design

- **External ID** = the Ticimax member id as a namespaced string
  (`tcx_<UyeID>`), never e-mail or phone: stable, not personal data in itself,
  already the `commerce_member` identity PratikTools trusts (F3 strategy
  `ticimax_member_id` resolves it).
- **Login**: the app calls `OneSignal.login("tcx_<UyeID>")` after a successful
  sign-in and on each session resume; with identity verification enabled, the
  app gets a JWT minted by a PratikTools (or Ticimax) endpoint for exactly that
  member, so a device cannot claim another customer.
- **Logout / account switch**: `OneSignal.logout()` on sign-out, then `login`
  for the next account (OneSignal moves the subscription to the new user and
  drops the previous user's tags/aliases).
- **Anonymous history**: existing subscriptions stay anonymous; they are linked
  only when the user next signs in. No back-filling by guess (no matching by
  device, e-mail or phone).
- **Wrong-match protection**: only `tcx_` ids of existing `commerce_member`
  identities resolve; profiles whose external id maps to more than one customer
  or to a pending merge stay `conflict`; PratikTools never writes external ids
  itself (REST "create user"/"transfer subscription" stay unused).

## Steps

1. Ask Ticimax (written answers to the four questions above).
2. If supported: configure the app (Ticimax) to log in with `tcx_<UyeID>`;
   enable identity verification if offered.
3. PratikTools: `onesignal strategy --strategy=ticimax_member_id` (with the
   `tcx_` prefix rule), nightly read-only sync, report the resolved share.
4. Only after a measured resolved share: push intents for customers in
   automations; push sending stays off until separately approved.

If Ticimax cannot set an external id, push stays a broadcast-only channel
(no per-customer automation) for IGS.
