# 03 — Signup & tenant provisioning

**Audience:** developers working on makerfolio-saas.
**Scope:** how a public signup becomes a live tenant schema, and how ownership of an existing tenant is transferred to a new email.

> A signup is a *pending* row in `public.signups` — no schema, no tenant — until someone clicks the emailed verification link. Verify then runs `Tenant::provision()` (the one place that `CREATE SCHEMA`s), drives `PENDING_VERIFICATION → ACTIVE` through the real state machine, and lands the site `site_published=0` so it sits behind a "coming soon" gate until the owner publishes. Transfer is a separate token flow that swaps the primary admin in-place.

## Map — the files
| File | Role |
| --- | --- |
| [`includes/Signup.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/Signup.php) | `public.signups` model; validators (`isValidEmail`/`isValidPassword`/`isValidHandle` via `Tenant`), token create (sha256-at-rest), `findActiveByTokenHash`, `isExpired`, `markVerified`. |
| [`includes/SignupRateLimit.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/SignupRateLimit.php) | Sliding-window caps on `public.signup_attempts`: 5/IP/hour, 1/email/day. |
| [`includes/HCaptcha.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/HCaptcha.php) | hCaptcha gate; no-op when `HCAPTCHA_SITEKEY` is empty. |
| [`public/signup/index.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/signup/index.php) | The signup form + POST handler (rate-limit → captcha → validate → `Signup::create` → mail). |
| [`public/signup/verify.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/signup/verify.php) | Token → `Tenant::provision` → `transitionTo('ACTIVE')` → `markVerified`. |
| [`public/signup/check-handle.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/signup/check-handle.php) | XHR live handle-availability JSON (`available`/`reason`). |
| [`public/signup/resend.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/signup/resend.php) | Rotate token + re-send verification for a pending signup ≤14d old. |
| [`includes/Tenant.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/Tenant.php) | `provision`, `transitionTo`, `hardDelete`, `setSitePublished`, handle rules, rename, the state machine. |
| [`includes/TransferInvitation.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/TransferInvitation.php) | `public.transfer_invitations` model (token sha256-at-rest, `markAccepted`/`revoke`/`isExpired`). |
| [`public/transfer/accept.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/transfer/accept.php) | Token → set new owner password → swap `admin_users` rows → re-point `primary_admin_email`. |
| [`bin/provision-tenant.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/provision-tenant.php) | CLI provision (skips verify; `status=ACTIVE`, `site_published=1`). |
| [`bin/seed-demo-tenant.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/seed-demo-tenant.php) | Provision + populate a full demo tenant; `--reset` rebuilds. |

## The flow

**1. Form POST.** [`index.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/signup/index.php) is apex-only (TenantResolver 404s on subdomains). On POST it `csrf_verify()`s, then gates in order: per-IP block (`index.php:30`), per-email block → silent success for anti-enumeration (`index.php:39-41`), `SignupRateLimit::record` *before* validation so even invalid tries count (`index.php:47`), then hCaptcha (`index.php:50-55`) and field validation (`index.php:57-71`). The per-email cap is checked **before** recording so the current submission doesn't count itself out (`SignupRateLimit.php:43`, comment at `index.php:36-38`).

**2. Pending row.** On clean input, `Signup::create` ([`Signup.php:40`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/Signup.php)) inserts a `public.signups` row with a bcrypt `password_hash` and `verification_token_hash = sha256(rawToken)`. The raw 64-hex token is returned once and only emailed — the DB stores the hash (`Signup.php:50-51`), deliberately deviating from MODELS.md's plaintext sketch (docstring `Signup.php:6-11`). Same "check your inbox" page renders for true success and for the in-use-email case (`index.php:39-41, 85`) — no account enumeration.

**3. Verify → provision.** [`verify.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/signup/verify.php) validates token shape (`64` hex, `verify.php:39`), looks up an unverified, un-abandoned row (`Signup::findActiveByTokenHash`, `Signup.php:66`), rejects expired (`verify.php:52`, TTL 7d). Then in one `Database::transaction` (`verify.php:60-78`):
- `Tenant::provision(handle, email, {password_hash, status:'PENDING_VERIFICATION', site_published:0})` (`verify.php:61-73`) — note `site_published => 0` (`verify.php:72`), the coming-soon default for new members;
- `Tenant::transitionTo(id, 'ACTIVE', 'signup_verified')` (`verify.php:74`) so the verification path runs through the state machine rather than writing `ACTIVE` directly;
- `Signup::markVerified(signupId, tenantId)` stamps `verified_at` + links `tenant_id` (`Signup.php:85`).

Auto-login is intentionally skipped — session cookies don't cross the apex→subdomain boundary (docstring `verify.php:11-15`); the success page points at the tenant's `/admin/login.php`.

**4. `Tenant::provision` internals** ([`Tenant.php:114`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/Tenant.php)). Validates handle / reserved / uniqueness / email (`Tenant.php:117-128`), then inside a transaction: insert `public.tenants` with placeholder `schema_name='__pending__'`, UPDATE it to `tenant_<id>` once the id is known (`Tenant.php:151-164`); `CREATE SCHEMA` + `setSchema` (`Tenant.php:168-169`); `Installer::initializeSchema` runs `sql/init.postgres.sql` building the app tables + pre-seeded `schema_migrations` + seed settings/page_sections (`Tenant.php:175-176`); override `settings.site_name` to the handle (`Tenant.php:180`); insert the first `admin_users` row (`Tenant.php:188-193`); `resetSchema` back to public before returning (`Tenant.php:198`). Postgres DDL is transactional, so a mid-flight failure rolls back the row insert **and** the `CREATE SCHEMA` atomically (docstring `Tenant.php:103-107`).

`site_published` defaults to `1` (`Tenant.php:146`) — CLI/demo/operator tenants are visible immediately; only the signup path passes `0`.

**5. Publish gate.** While `site_published=0`, `TenantResolver::isComingSoonGated` ([`TenantResolver.php:48`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/TenantResolver.php)) renders a placeholder for anonymous public hits but never for `/admin*` and never for a signed-in viewer (owner preview / support session). The owner flips it via `Tenant::setSitePublished` (`Tenant.php:394`), which also invalidates the resolver cache so the change is immediate in-worker. `isSitePublished` fails **open** — a lookup hiccup defaults to published (`Tenant.php:410-414`).

**6. Resend.** [`resend.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/signup/resend.php) re-finds a pending row by email (≤14d, `resend.php:45-54`), rotates `verification_token_hash` + refreshes `verification_sent_at` (`resend.php:55-64`), re-mails. Records against the same caps so it can't bypass `/signup/`'s limits (`resend.php:40`), and renders success regardless of outcome (same no-enumeration semantics).

**Transfer ownership.** The inviter (a tenant admin) creates a `public.transfer_invitations` row via `TransferInvitation::create` ([`TransferInvitation.php:30`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/TransferInvitation.php)) — cleartext token returned once, sha256 stored. The recipient hits [`accept.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/transfer/accept.php): per-IP rate-limit (`transfer_accept`, 30/hr, `accept.php:42`), `findByToken` (hash-then-compare, `accept.php:49`), status/expiry/tenant-state checks (must be `PENDING` + `ACTIVE`/`GRACE`, `accept.php:53-67`). On POST (password ≥12, confirm, explicit checkbox, `accept.php:77-85`) one transaction (`accept.php:98-166`): upsert the new owner's `admin_users` row in the **tenant** schema (promote-in-place if the email already exists, else insert with a uniqueness-suffixed username), DELETE the old primary (matched by pre-swap `primary_admin_email`), re-point `public.tenants.primary_admin_email`, `markAccepted`, and an `ActivityLog` audit row. Then `Auth::completeLocalLogin(newAdminId)` logs the new owner in (`accept.php:171-176`) and redirects to the tenant subdomain's `/admin/dashboard` (built manually since `SITE_URL` here is the apex, `accept.php:189-197`). The "transfer completed" mail to the old owner is sent **outside** the transaction (`accept.php:180-184`).

## State machine

`Tenant::ALLOWED_TRANSITIONS` ([`Tenant.php:211-218`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/Tenant.php)) — source of truth mirrored in MODELS.md "Status state machine":

```
PENDING_VERIFICATION → ACTIVE | SUSPENDED
ACTIVE               → GRACE | SUSPENDED | PENDING_DELETION
GRACE                → ACTIVE | SUSPENDED
SUSPENDED            → ACTIVE | PENDING_DELETION
PENDING_DELETION     → ACTIVE | SUSPENDED | DELETED
DELETED              → (terminal)
```

`transitionTo` (`Tenant.php:220`) rejects illegal transitions, then in one transaction UPDATEs the row and writes a `public.platform_admin_activity` audit row (`platform_admin_id` null for system/cron-initiated, `Tenant.php:276-282`). Per-target side-effects:
- `SUSPENDED` stamps `suspended_at` + a normalized `suspension_reason` (`Tenant.php:235-239`).
- `PENDING_DELETION` stamps `deletion_requested_at` + `deletion_scheduled_at = now()+30d` — the timer the sweep cron reads; centralized here so user-initiated delete gets it too (`Tenant.php:240-255`).
- `PENDING_DELETION → ACTIVE` clears both timers (`Tenant.php:256-266`).

After commit it invalidates the resolver cache for the tenant (`Tenant.php:289-291`) and fires lifecycle emails, each guarded by `$current !== $newStatus` so a no-op re-run doesn't refire (`Tenant.php:297-324`); mail failures are logged, never block the transition.

`hardDelete` (`Tenant.php:427`) is **not** a `transitionTo` — it `DROP SCHEMA ... CASCADE` + flips the row to `DELETED` with `handle_released_at = now()+90d` and renames `schema_name` to `__deleted_<id>` (so the UNIQUE constraint doesn't collide), all in one transaction, guarded by a `^tenant_[0-9]+$` regex that refuses to drop anything else (`Tenant.php:434-437`). Called by the sweep cron once `deletion_scheduled_at` passes.

`TransferInvitation` uses lightweight explicit methods rather than a `transitionTo`: `PENDING → ACCEPTED|EXPIRED|REVOKED` (`TransferInvitation.php:77-98`). `EXPIRED` only fires via the sweep cron, but a past-`expires_at` `PENDING` row is rejected at the accept endpoint regardless (docstring `TransferInvitation.php:11-15`). For the `tenant_domains` state machine see ARCHITECTURE.md §3 and [`10-custom-domains-tls.md`](./10-custom-domains-tls.md).

## Invariants & gotchas
- **`Tenant::provision` is the only `CREATE SCHEMA` site** (`Tenant.php:168`); `hardDelete` the only `DROP SCHEMA` (`Tenant.php:440`). CLAUDE.md invariant 1 — never `WHERE tenant_id`.
- **Tokens are sha256-at-rest** for both signups (`Signup.php:50-51`) and transfers (`TransferInvitation.php:36-37`). A DB leak can't be replayed into attacker-controlled tenants/ownership. Deliberate deviation from MODELS.md's plaintext sketch.
- **No account enumeration.** Signup, resend, and the in-use-email path all render the same success page (`index.php:39-41`, `resend.php:28-31`).
- **`site_published=0` on signup only.** Signup passes `0` (`verify.php:72`); everything else defaults `1` (`Tenant.php:146`). The gate is enforced in the resolver, not the controllers (`TenantResolver.php:48`).
- **Verify drives the state machine, not a raw `ACTIVE` write.** Provision lands `PENDING_VERIFICATION`, then `transitionTo('ACTIVE')` (`verify.php:74`) — keeps the audit row + transition guard honest.
- **Per-email cap checked before record** (`SignupRateLimit.php:43` / `index.php:36-47`) — recording first would block the first-ever signup for any email.
- **Transfer requires `ACTIVE`/`GRACE`** (`accept.php:65`); suspended / pending-deletion tenants can't be transferred.
- **Provision resets `search_path` before returning** (`Tenant.php:198`) so the caller doesn't inherit tenant context — matters because verify wraps provision in its own outer transaction.

## Tests & verification
- [`bin/signup-smoke.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/signup-smoke.php) (live Postgres) — proves: handle rules classify (invalid/reserved/taken/fresh); `Signup::create` stores the **hash** not the raw token; Mailer (`log` driver) captures the verify URL; the full verify path provisions `PENDING_VERIFICATION → ACTIVE`, marks the signup verified + linked, and the new admin can `Auth::loginLocal` against `tenant_<id>`; both rate-limit caps enforce. Self-cleans (drops schema + public rows).
- [`bin/account-transfer-smoke.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/account-transfer-smoke.php) (live Postgres) — proves: token sha256-at-rest + `findByToken` by cleartext (null for unknown); the accept swap (insert new / delete old / re-point `primary_admin_email` / `markAccepted` / audit) lands; the sweep flips a backdated `PENDING → EXPIRED`; `revoke` flips `→ REVOKED` while `findByToken` still resolves.
- [`tests/SignupValidationTest.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/tests/SignupValidationTest.php) — `isValidEmail`/`isValidPassword`/`isExpired` (fails closed on missing/garbage timestamps).
- [`tests/TenantTest.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/tests/TenantTest.php) — handle regex (3–30, edge hyphen, case, non-ascii), two-letter-code detection, reserved-list lowercase invariant, and `ALLOWED_TRANSITIONS` matches the MODELS.md keyset with no stray targets.
- [`tests/TransferInvitationTest.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/tests/TransferInvitationTest.php) — TTL=7d, `isExpired` true for past/missing/empty.
- [`tests/HCaptchaTest.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/tests/HCaptchaTest.php), [`tests/TenantResolverTest.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/tests/TenantResolverTest.php) — captcha-disabled no-op and the `isComingSoonGated` decision table.

DB-touching methods (`provision`, `transitionTo`, `findByToken`) are covered by the smokes, not unit tests, per the testing convention in CLAUDE.md.

## See also
- [`01-tenancy-bootstrap-routing.md`](./01-tenancy-bootstrap-routing.md), [`02-auth-and-security.md`](./02-auth-and-security.md), [`09-migrations.md`](./09-migrations.md)
- [design-docs/ARCHITECTURE.md](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/ARCHITECTURE.md) §5 "Tenant provisioning", §2 reserved handles
- [design-docs/MODELS.md](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/MODELS.md) — `signups`, `tenants`, `transfer_invitations` shapes + status state machine
