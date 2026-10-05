# 06 — Security architecture

## Three auth keyspaces, one session (invariant 6)

A single browser session can simultaneously hold three non-overlapping identities:

| Keyspace | Table | Surface | Auth |
|---|---|---|---|
| `platform_admin_id` | `public.platform_admin_users` | `/platform-admin/` (apex only) | Local password or GitHub/Google OAuth; **TOTP 2FA mandatory** (login refuses to complete without enrollment); password and TOTP steps share one per-IP rate limit; `is_superadmin` gates delete-tenant / edit-plans / manage-admins / support sessions |
| `admin_id` | `<tenant>.admin_users` | `<tenant-host>/admin/` | Local password (email or username) + optional OAuth + optional TOTP; per-tenant — user id 1 exists in every tenant with no relation |
| anonymous shop customer | — | tenant public site | Identified by email at checkout; Stripe holds payment PII |

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
  brute-forced separately). OAuth `state` compared with `hash_equals`; Google sign-in requires a
  verified email.
- **TOTP replay**: each admin row records the last accepted time step (`totp_last_step`, tenant
  and platform), so a code that already worked can't be replayed inside its ±1-step window.
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
`platform_admin_activity` — the latter also records what every state-changing sweep cron did.
Three security audits have run: a scoped pre-launch pass over the admin, platform, signup and
webhook surfaces, then two full-codebase passes.

- **2026-05-30**: no HIGH findings; MEDIUM/LOW defense-in-depth gaps fixed (path-traversal anchor,
  `public.*` qualification, table-name whitelists).
- **2026-08**: 22 findings (2 High, 5 Medium, 10 Low, 5 Info) and no cross-tenant leak. The Highs
  were vulnerable transitive dependencies and an un-rate-limited platform-admin login. The rest
  produced most of the hardening listed above: the TOTP replay guard, trusted-proxy correction,
  internal-only `/caddy-ask`, MIME-derived upload extensions + the edge deny, the pixel-flood
  guard, HSTS, plan-cap fail-closed, the Connect webhook's transaction fix (see
  [05-billing](05-billing-and-payments.md)), and gating the webhook test-mode bypass to non-FPM
  contexts. Every finding has a merged fix except the deliberate `img-src https:` tradeoff.

An accessibility review ran in the same window (71 pages, WCAG 2.1 A/AA ruleset); its findings
were fixed in follow-up PRs. It isn't a security control, but it shared the audit's
"crawl everything, fix by class" method.
