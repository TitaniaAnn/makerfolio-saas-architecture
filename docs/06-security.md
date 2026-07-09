# 06 — Security architecture

## Three auth keyspaces, one session (invariant 6)

A single browser session can simultaneously hold three non-overlapping identities:

| Keyspace | Table | Surface | Auth |
|---|---|---|---|
| `platform_admin_id` | `public.platform_admin_users` | `/platform-admin/` | Local password or GitHub/Google OAuth; **TOTP 2FA mandatory** (login refuses to complete without enrollment); `is_superadmin` gates delete-tenant / edit-plans / manage-admins |
| `admin_id` | `<tenant>.admin_users` | `<tenant-host>/admin/` | Local password (email or username) + optional OAuth + optional TOTP; per-tenant — user id 1 exists in every tenant with no relation |
| anonymous shop customer | — | tenant public site | Identified by email at checkout; Stripe holds payment PII |

## Tenant data isolation — three layers

1. **`search_path`** (the primary mechanism): set once in bootstrap; forgetting it fails *loud*
   ("table does not exist" against the public schema), never as a silent leak.
2. **No cross-schema FKs** (invariant 2): schemas stay independently dump/drop/restore-able.
   Deliberate cross-schema queries are `public.*`-qualified so a stray search_path can't
   misdirect them.
3. **Per-tenant Postgres roles** with USAGE only on their schema — used for the support flow and
   emergency DBA work, not the app pool (defense in depth).

### Cross-tenant attack surface, closed point by point

- **Session replay across tenants**: session state is tenant-checked — a cookie minted on
  `annie.makerfolio.art` fails the tenant match on `bob.makerfolio.art`.
- **CSRF tokens** are session-bound, so a token from tenant A is useless against tenant B.
- **Uploads**: every storage key is tenant-id-prefixed; no path traversal survives Caddy
  normalization + the `ImageUpload::delete` anchor check (must resolve under `UPLOAD_PATH`).
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
  fail-closed) cover ~60 admin pages: OWNER-only for billing/users/account/domains/settings,
  EDITOR+ for content, CONTRIBUTOR edit-own on pieces.
- **Login rate limiting**: 5 failures/IP/10 min via `login_attempts`; `auth_attempts` extends
  this to forgot-password and transfer-accept endpoints. OAuth `state` compared with
  `hash_equals`.
- **Trusted proxies**: client IP honors `X-Forwarded-For`/`CF-Connecting-IP` only when
  `REMOTE_ADDR` is loopback, in `TRUSTED_PROXIES`, or in Cloudflare CIDRs — otherwise spoofable
  headers could bypass rate limits.
- **CSP**: fully tightened post-launch — **zero** inline `<script>`, `on*=` handlers, `<style>`
  blocks, or `style=""` attrs remain; `script-src`/`style-src` are `'self'` + named CDNs (plus a
  per-request nonce for the dynamic theme block). Plus `Referrer-Policy`,
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`.
- **Upload execution lockdown**: the upload tree refuses to execute `.php*` (defense in depth
  behind upload validation).
- Parameterized queries only, via the `Database` helpers.

## Support sessions — audited "log in as tenant"

The operator can enter any tenant's admin, but the flow is designed to be **unilateral yet fully
audited**:

1. Start from the tenant's platform-admin detail page; a free-text **reason is required**.
2. A `public.support_sessions` row is created (admin, tenant, reason, 1-hour expiry) and a
   synthetic `admin_activity` row is written *in the tenant's schema* ("Support session by … :
   reason") — so the tenant can see it in their own activity log.
3. Every admin page renders a persistent red banner with a countdown and an "End session" button.
4. Every write during the window stamps `via_support=true` + the platform admin id on the tenant's
   `admin_activity` rows.
5. Support sessions never consume a tenant `admin_users` seat.

## Certificate & domain abuse

Covered in [03-routing-and-tls.md](03-routing-and-tls.md): the `/caddy-ask` allowlist (invariant
7) means cert issuance requires a completed DNS TXT ownership challenge, and cert renewal stops
for long-suspended/deleted tenants.

## Content / mail / phishing abuse

- **Signup abuse**: hCaptcha + per-IP and per-email rate limits (`signup_attempts`); a public
  "Report this site" link on every tenant footer feeds `tenant_reports` and the operator queue.
- **Outbound mail abuse**: per-plan daily send caps; SES bounce/complaint webhook (SNS-signature
  verified) suppresses addresses and feeds a 3-bounces-in-24h guard that fails a tenant's sender
  identity; Studio white-label senders get **tenant-scoped DKIM reputation** so one bad actor
  can't burn the platform identity.
- **Phishing custom domains**: lookalike-brand blocklist at `tenant_domains` insert; new custom
  domains surface in the operator's monitoring queue.
- The catch-all is the operator's suspend button — deliberate "good defaults + visible controls"
  posture rather than a speculative anti-abuse system.

## Audit posture

Two append-only audit logs (no update/delete UI even for superadmins; legal redaction is a
separate referencing row): per-tenant `admin_activity` and platform-wide
`platform_admin_activity` — the latter also records what every state-changing sweep cron did.
Two full-codebase security audits were run pre- and post-launch (second pass: no HIGH findings;
MEDIUM/LOW defense-in-depth gaps fixed — path-traversal anchor, `public.*` qualification,
table-name whitelists).
