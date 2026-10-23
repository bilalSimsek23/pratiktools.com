# Secret vault: platform KEK, rotation and escrow

Production chain: **Laravel Cloud Secret → platform KEK → tenant DEK →
integration secrets**.

- Each tenant has its own data key (DEK, AES-256-GCM). DEKs are stored only
  wrapped by the platform KEK; integration secrets are encrypted with the
  tenant's DEK and bound to tenant, integration, provider and capability.
- The platform KEK (`SECRET_VAULT_KEK`, driver `cloud_secret`) is 32 random
  bytes, base64. It exists only in the Laravel Cloud Secret, the deployed
  process environment and the offline escrow. Never in the database, cache,
  logs, admin panel or API.
- Every data key records the id of the KEK that wraps it:
  `cs-` + first 16 hex of HMAC-SHA256(KEK, "pratiktools.kek-id.v1").
  The id is derived from the key, so a wrong key can never carry the right
  label. `local-…` ids are development only; `arn:aws:kms:…` ids belong to
  the optional AWS KMS driver.
- `php artisan vault:verify-kek` runs before migrations in every deploy and
  stops the deploy if any data key in use cannot be opened.

## What a leak of each part means

| Leaked | Impact |
| --- | --- |
| Database dump or backup only | Nothing readable: DEKs are wrapped, the KEK is not in the database. |
| KEK only | Nothing without the database. |
| KEK + database (current or any old backup) | Every integration secret in that copy. Rotation does not protect copies taken before it. |

## Who can read the KEK (Laravel Cloud Commands risk)

Laravel Cloud writes environment variables, linked secrets included, to the
application's `.env` file. Anyone who can run a command in the environment
("Commands" tab: `cat .env`, `php artisan config:show`, `tinker`) can read
the KEK. That permission is therefore equivalent to holding the KEK.

- Grant Commands, secret edit and secret linking to at most two people,
  with two-factor authentication.
- The application exposes no env dump, config dump, phpinfo or debug
  endpoint; `APP_DEBUG=false` is enforced by the production guard.
- Logs redact `SECRET_VAULT_*`, `APP_KEY`, `AUDIT_HASH_KEY`, fingerprint and
  database/Redis passwords by name and by value.
- Never define `SECRET_VAULT_KEK` as a custom environment variable: a custom
  variable silently overrides the linked secret.

## Generating a KEK

On a trusted workstation (refused in production, staging and on Laravel
Cloud):

```sh
php artisan vault:generate-kek
```

It prints `SECRET_VAULT_KEK=…` and `kek_id=cs-…` once. Store the key in the
escrow and as the Laravel Cloud Secret, record the kek_id next to it, clear
the terminal scrollback. Nothing is logged or written to disk.

## Normal rotation (planned, no compromise)

1. `php artisan vault:generate-kek` → new key K2, kek_id `cs-…`.
2. Put K2 into the offline escrow (see below), with its kek_id and date.
3. Laravel Cloud Secrets: create `SECRET_VAULT_KEK_PREVIOUS` with the current
   key K1 and link it to the environment.
4. Update `SECRET_VAULT_KEK` to K2.
5. Deploy. The deploy runs `vault:verify-kek` first: data keys under K1 open
   through the previous key, so it passes. If it fails, the deploy stops and
   production keeps running the old release; fix the secrets and redeploy.
6. Cloud Commands: `php artisan vault:verify-kek` (expect PASS).
7. Cloud Commands: `php artisan vault:rewrap --all`. Re-wraps only data keys
   not under K2, tenant by tenant, one transaction each; secrets are not
   re-encrypted. Safe to stop and run again: finished tenants are skipped.
8. `php artisan vault:verify-kek` again: the only kek_id in use is K2's.
9. Keep K1 in escrow until every backup and point-in-time-recovery window
   taken before step 7 has expired (restoring such a backup needs K1). Then
   unlink and delete `SECRET_VAULT_KEK_PREVIOUS`.
10. Deploy again; `vault:verify-kek` must pass with K2 alone.

`SECRET_VAULT_KEK_PREVIOUS` holds at most 3 comma-separated base64 keys and
is defined only during a rotation. Duplicates and a previous equal to the
current key are refused at boot.

## Compromise rotation

If the KEK, an escrow copy, a `.env` file or an environment with Commands
access may have been exposed, assume the attacker also has (or will get) a
database copy. Rotating the KEK alone is **not enough**: the attacker may
already hold unwrapped DEKs or plaintext secrets.

Do all three, in this order:

1. **KEK rotation** – steps 1–8 above, immediately; keep K1 only as long as
   needed for step 2, then delete it everywhere except a sealed escrow entry
   marked compromised (needed only to restore pre-incident backups).
2. **Tenant DEK rotation** – for every tenant:
   `php artisan vault:rotate-data-key --tenant=<slug>`. New DEKs are created
   and every secret is re-encrypted, so old DEKs become useless.
3. **Provider credential rotation** – every credential stored in the vault
   (Ticimax member codes, İYS API users, Klaviyo/OneSignal keys, …) is
   rotated at the provider and re-entered (`ticimax:connect`, `iys:connect`,
   API `PUT /integrations/{id}/secret`). Only this makes stolen plaintexts
   worthless.

Also: rotate `APP_KEY`, `SECRET_VAULT_FINGERPRINT_KEY` and `AUDIT_HASH_KEY`
if the same exposure covered them, review audit logs (`secret.decrypted`,
`vault.*`) for the exposure window, and record the incident.

## Offline escrow

Losing the KEK (secret deleted, no copy) makes every integration credential
unrecoverable: every tenant must re-enter its provider credentials. The
Laravel Cloud Secret cannot be read back ("write once, read never"), so an
escrow copy is mandatory.

Requirements, whatever product is used:

- **Two authorized people** hold access; no single person can retrieve the
  key alone where the medium allows it (shared vault with approval, split
  custody, or two sealed copies in separately controlled places).
- **Separate from the application environment**: never in Laravel Cloud, the
  repository, the database, application logs, chat or e-mail.
- **Access is logged**: every retrieval is recorded with who, when and why.
- **Each entry records**: kek_id, creation date, status (current / previous
  / retired / compromised), who stored it.
- **Recovery test**: at least twice a year and after every rotation, an
  authorized person retrieves the key and proves it matches, without
  touching production:
  `php -r 'echo "cs-".substr(hash_hmac("sha256","pratiktools.kek-id.v1",base64_decode(trim(fgets(STDIN)),true)),0,16),PHP_EOL;'`
  (paste the key on standard input) must print the recorded kek_id.
- Retired keys stay in escrow until no backup that needs them exists.
