# merchant_verified consent strategy (IGS)

A tenant-specific consent strategy, chosen explicitly by an operator, for a
tenant without İYS API access: the tenant has **operationally verified**
that its existing store (Ticimax) e-mail permissions are valid commercial
message consents, with İYS records and proof on the tenant's side.

It is **not** an İYS API or ViA verification and is never shown as one
(label: "Mağaza izni (operasyonel doğrulama, İYS doğrulaması değil)"). The
default strategy stays `direct_api`; nothing switches it automatically, and
switching it changes no sending mode.

## Decision (SendEligibility, one SQL for audience, readiness and send time)

First match wins; every negative record beats a store permission:

1. invalid address → `INVALID_EMAIL`
2. marketplace relay address → `MARKETPLACE_IDENTITY`
3. active suppression: unsubscribe, hard bounce, complaint, manual, soft
   bounce threshold, İYS RET suppression → `LOCAL_SUPPRESSED`
4. an official RET (İYS / ViA / applied İYS report) → `IYS_REVOKED`
5. strategy not operational → `MERCHANT_CONSENT_STALE` (everyone)
6. latest store e-mail permission (Ticimax member `UyeIzin` or newsletter
   `MailList`; RET wins a tie) is not GRANTED → `MERCHANT_NOT_GRANTED`
7. an explicit store opt-out or an unsubscribe recorded after that
   permission → `MERCHANT_NOT_GRANTED`
8. address shared by several customers → `IDENTITY_CONFLICT`
9. otherwise `ALLOWED`

A lead form checkbox (`LEAD_FORM`) is never a permission. The frequency cap
and the per-message send guard still apply on top.

## Operational (hard requirement)

- an attestation exists (reason, evidence reference, operator, time);
- the store (Ticimax commerce) integration is `connected`;
- its permission history sync (`ticimax_consent_history`) succeeded within
  `CONSENT_MERCHANT_MAX_AGE_HOURS` (default 24).

Otherwise the strategy blocker is one of `MERCHANT_ATTESTATION_MISSING`,
`MERCHANT_COMMERCE_NOT_CONNECTED`, `MERCHANT_CONSENT_POLICY_MISSING`,
`MERCHANT_CONSENT_SYNC_MISSING`, `MERCHANT_CONSENT_STALE`, and nobody is
eligible.

## Activation (operator, audited)

```
php artisan consent:strategy --tenant=<slug>                      # show
php artisan consent:sync --tenant=<slug> --kind=ticimax_history --inline
php artisan consent:strategy --tenant=<slug> --set=merchant_verified \
  --reason="..." --evidence-ref="<where the proof is kept>" --operator="<name>"
```

Stored in `tenant_settings.consent_strategy_attestation` (not readable by the
platform role; a CHECK keeps merchant_verified from existing without it) and
in the `consent.strategy_changed` audit event. Switching back
(`--set=direct_api --reason=...`) clears the attestation.

## Readiness

The "11 Kasım E-posta Hazırlığı" card and the panel's external readiness
are split into **delivery** (must all PASS) and **acquisition** (warnings:
ViA Frame / OTP, İYS API access when the strategy does not use it). Missing
İYS API access or an inactive ViA Frame never blocks sending to the audience
that already consented under merchant_verified. The card's audience is the
number of e-mail recipients `ALLOWED` now, with the reason breakdown.
