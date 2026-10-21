# 06 — Security architecture

## Three auth keyspaces, one session (invariant 6)

A single browser session can simultaneously hold three non-overlapping identities:

| Keyspace | Table | Surface | Auth |
|---|---|---|---|
| `platform_admin_id` | `public.platform_admin_users` | `/platform-admin/` (apex only) | Local password or GitHub/Google OAuth; **TOTP 2FA mandatory** (login refuses to complete without enrollment); password and TOTP steps share one per-IP rate limit; `is_superadmin` gates delete-tenant / edit-plans / manage-admins / support sessions |
| `admin_id` | `<tenant>.admin_users` | `<tenant-host>/admin/` | Local password (email or username) + optional OAuth + optional TOTP; per-tenant — user id 1 exists in every tenant with no relation |
| anonymous shop customer | — | tenant public site | Identified by email at checkout; Stripe holds payment PII |

The operator team itself is managed at `/platform-admin/team/` (superadmin-only, every mutation
audited) with lockout guards as pure, unit-tested decisions: no self-deactivation, no revoking
your own superadmin, and never zero active superadmins (otherwise recovery means the CLI). New
accounts get a temp password shown once and detour through 2FA enrollment on first login — the
mandatory-2FA invariant holds because no session is ever established for an unenrolled row.

## Session revocation — epochs on both planes

Every tenant `admin_users` row and every `public.platform_admin_users` row carries a
`session_epoch`. Login stamps the current value into the session; every request re-checks it, so
bumping the epoch evicts that account's *other* live sessions on their next request. Bumps fire
wherever "this credential may be compromised" is the subtext: password change (admin-UI edit or
forgot-password reset), role change (a demoted OWNER loses OWNER powers on the open session, not
at the next login), 2FA reset, and — on the platform plane — deactivation or superadmin
revocation. The tenant-side check fails **closed** when the admin row is gone (deleted admin =
dead session) and fails open only when the column doesn't exist yet (an un-migrated self-host
schema); the platform-side check additionally re-reads `is_active` on every request. The
mechanism arrived across the 2026-10 audit campaign: the epoch columns and password-path bumps
first, then the campaign's only High (AUTH-H1, sixth pass) caught that the deactivate/demote
"evict now" controls changed the database but left live sessions valid until cookie expiry —
closed the same day by wiring them into the same bump.

## Tenant data isolation — three layers

1. **`search_path`** (the primary mechanism): set once in bootstrap; forgetting it fails *loud*
   ("table does not exist" against the public schema), never as a silent leak.
2. **No cross-schema FKs** (invariant 2): schemas stay independently dump/drop/restore-able.
   Deliberate cross-schema queries are `public.*`-qualified so a stray search_path can't
   misdirect them.
3. **Tenant-rooted storage keys**: `TenantStorage` prepends the tenant's `<id>/` root and throws
   on a key rooted in another tenant's prefix, so object storage gets the same structural
   isolation as the database (see [07-operations](07-operations.md)).

Per-tenant Postgres roles appear in the design docs as a fourth layer; they aren't built.

### Cross-tenant attack surface, closed point by point

- **Session replay across tenants**: the session cookie is host-only (no `Domain` attribute), so
  the browser never sends a cookie minted on `annie.makerfolio.art` to `bob.makerfolio.art`. The
  `admin_id` itself is not re-checked against the tenant; support sessions are, re-validating
  `tenant_id` against the resolved tenant on every request. The cookie is `Secure` whenever
  `SITE_URL` is https, independent of proxy configuration.
- **CSRF tokens** are session-bound, so a token from tenant A is useless against tenant B.
- **Uploads**: every storage key is tenant-id-prefixed and assembled only by `TenantStorage`;
  key segments of `..`, `.` or empty are rejected. The `ImageUpload::delete` anchor check (must
  resolve under `UPLOAD_PATH`) still guards the local-disk path the self-host build uses.
- **Webhooks**: platform events dedup in `public.billing_events`; each tenant's shop events dedup
  in its own schema; Connect events resolve by the unique connected-account index.
- **Subdomain takeover**: released handles have a 90-day cooldown (DELETED tombstone row) so an
  attacker can't re-register `annie` and inherit third-party verifications pointed at
  `annie.makerfolio.art`.

## Inherited hardening contracts (per-tenant surface)

Carried over from the single-tenant CMS and treated as contracts:

- **CSRF**: every admin POST and GET-style delete calls `csrf_verify()`; failure redirects to the
  referer only if same-origin (open-redirect closed).
- **Auth gates**: `Auth::requireLogin()` + `Cache-Control: private, no-store` on admin pages
  (shared-device back-button cache). Role gates (`require_role(...)`, `includes/Role.php`,
  fail-closed) cover ~75 admin pages: OWNER-only for billing/users/account/domains/settings,
  EDITOR+ for content, CONTRIBUTOR edit-own on pieces.
- **Login rate limiting**: 5 failures/IP/10 min via `login_attempts` for tenant admins;
  `auth_attempts` extends this to forgot-password, transfer-accept, and the platform-admin
  console (password and TOTP challenge feed one counter, so the second factor can't be
  brute-forced separately).
- **OAuth**: `state` nonces live in per-provider session slots (`OAuthState`, one namespace per
  auth plane) and are compared with `hash_equals` — a login page rendering two providers' buttons
  can't clobber one nonce with the other. Google sign-in requires a verified email. OAuth login
  honors the account's TOTP: it detours through the same 2FA challenge as a password login, so an
  enabled second factor can't be bypassed by "Sign in with GitHub" (2026-10 audit, SEC-3). And
  OAuth *provisions* a new admin only on an immutable provider id from the allow-list (GitHub
  numeric id / Google `sub`); a plain username or email signs in an already-linked account but
  never mints one, so a recycled GitHub username can't claim admin access (OAUTH-1).
- **TOTP replay**: each admin row records the last accepted time step (`totp_last_step`, tenant
  and platform), so a code that already worked can't be replayed inside its ±1-step window. The
  step advance is atomic (a conditional `UPDATE … WHERE totp_last_step IS NULL OR
  totp_last_step < :step`, accepted only when a row moved), so two racing requests presenting the
  same valid code can't both pass; recovery codes are consumed under a `SELECT … FOR UPDATE` row
  lock for the same reason (2026-10, ninth audit).
- **Trusted proxies**: client IP honors `CF-Connecting-IP`, then `X-Forwarded-For`, only when
  `REMOTE_ADDR` is loopback or in `TRUSTED_PROXIES` (exact IPs or CIDRs). PHP-FPM's direct peer is
  Caddy on the Docker network, so that is what gets trusted; Cloudflare's ranges deliberately
  are not.
- **CSP**: `script-src` is `'self'` + named CDNs with no inline script and no `on*=` handlers.
  `style-src` carries a per-request nonce for the few `<style>` blocks that must be dynamic (the
  tenant theme tokens, error and gate pages); no `style=""` attributes remain. `img-src` allows
  any `https:` host on purpose (tenants embed remote images). Plus `Referrer-Policy`,
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and HSTS at the edge.
- **Upload execution lockdown**: the upload tree refuses to execute `.php*`, and Caddy 404s any
  `/uploads/` path ending in an active-content type (HTML, SVG, XML, JS), so a file that slips
  past validation still can't run in the tenant's origin. Stored extensions come from the
  validated MIME type, never the client's filename.
- Raw exception text is never shown to users; it goes to the error log.
- Parameterized queries only, via the `Database` helpers.

## Support sessions — audited "log in as tenant"

The operator can enter any tenant's admin, but the flow is designed to be **unilateral yet fully
audited**:

1. Start from the tenant's platform-admin detail page (superadmins only); a free-text **reason is
   required**.
2. A `public.support_sessions` row is created (admin, tenant, reason, 1-hour expiry) and a
   synthetic `admin_activity` row is written *in the tenant's schema* ("Support session by … :
   reason") — so the tenant can see it in their own activity log.
3. Every admin page renders a persistent red banner with a countdown and an "End session" button.
4. Every write during the window stamps `via_support=true` + the platform admin id on the tenant's
   `admin_activity` rows.
5. Support sessions never consume a tenant `admin_users` seat.

## Certificate & domain abuse

Covered in [03-routing-and-tls.md](03-routing-and-tls.md): the `/caddy-ask` allowlist (invariant
7) means cert issuance requires a verified `tenant_domains` row, which only a tenant OWNER on a
plan with `allow_custom_domain` can create and only a completed DNS TXT challenge can verify; cert
renewal stops for long-suspended/deleted tenants.

## Content / mail / phishing abuse

- **Signup abuse**: captcha (Turnstile, hCaptcha fallback) + per-IP and per-email rate limits (`signup_attempts`); a public
  "Report this site" link on every tenant footer feeds `tenant_reports` and the operator queue.
- **Outbound mail abuse**: per-plan daily send caps; SES bounce/complaint webhook (SNS-signature
  verified) suppresses addresses and feeds a 3-bounces-in-24h guard that fails a tenant's sender
  identity; Studio white-label senders get **tenant-scoped DKIM reputation** so one bad actor
  can't burn the platform identity.
- **Phishing custom domains**: new custom domains surface in the operator's monitoring queue. The
  lookalike-brand blocklist in the design was never built; the add-time check refuses only the
  platform's own domain and hostnames already claimed by another tenant.
- The catch-all is the operator's suspend button — deliberate "good defaults + visible controls"
  posture rather than a speculative anti-abuse system.

## Audit posture

Two append-only audit logs (no update/delete UI even for superadmins; legal redaction is a
separate referencing row): per-tenant `admin_activity` and platform-wide
`platform_admin_activity` — the latter also records what every state-changing sweep cron did,
and the former now covers shop, order and social mutations, not just content edits (2026-10).
Security audits have run in waves: a scoped pre-launch pass over the admin, platform, signup and
webhook surfaces, then full-codebase passes.

- **2026-05-30**: no HIGH findings; MEDIUM/LOW defense-in-depth gaps fixed (path-traversal anchor,
  `public.*` qualification, table-name whitelists).
- **2026-08**: 22 findings (2 High, 5 Medium, 10 Low, 5 Info) and no cross-tenant leak. The Highs
  were vulnerable transitive dependencies and an un-rate-limited platform-admin login. The rest
  produced most of the hardening listed above: the TOTP replay guard, trusted-proxy correction,
  internal-only `/caddy-ask`, MIME-derived upload extensions + the edge deny, the pixel-flood
  guard, HSTS, plan-cap fail-closed, the Connect webhook's transaction fix (see
  [05-billing](05-billing-and-payments.md)), and gating the webhook test-mode bypass to non-FPM
  contexts. Every finding has a merged fix except the deliberate `img-src https:` tradeoff.
- **2026-10 (PRs #195–#226)**: a campaign of ten successive whole-repo audits (`CODE_AUDIT.md`
  in the product repo) over seven days — audit, same-day fix wave, re-audit. Fix waves covered auth and OAuth hardening, billing and shop correctness, Postgres
  dialect and CI coverage, XSS, and privacy; every finding ended merged-fixed or retained by a
  documented decision, with no Critical at any pass. Out of the campaign came the session-epoch
  revocation above, OAuth 2FA + immutable-id provisioning, per-provider OAuth state, the
  `checkout.session.expired` handling, and the in-schema `admin_id` FKs. The sixth pass's High
  (AUTH-H1) was the session-revocation gap described above — the instructive part being that five
  prior passes missed it because each verified the epoch *mechanism* without asking which "evict
  now" controls never called it. Passes seven through ten came back 0 Critical/High/Medium with
  single-digit Low lists, mostly consumed the same day; those later waves produced the shared
  `WebhookLedger` claim helper and `TestMode` gate (one definition each for the two most
  security-sensitive previously-copied blocks), the atomic TOTP step-advance and row-locked
  recovery-code consumption above, and dropped scanner-driven `/caddy-ask` denials from the
  operator audit log (they flooded it; denials still 404 and domain state is still observed by
  the TLS probe).

An accessibility review ran in the same window (71 pages, WCAG 2.1 A/AA ruleset); its findings
were fixed in follow-up PRs. It isn't a security control, but it shared the audit's
"crawl everything, fix by class" method.
