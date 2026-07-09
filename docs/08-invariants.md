# 08 — Invariants and key decisions

## The seven load-bearing invariants

These are contracts, not suggestions. Each exists because breaking it causes a data leak, a
billing bug, or an outage class the design specifically set out to eliminate.

### 1. Tenancy is schema-per-tenant via `search_path` — never a `tenant_id` WHERE clause
Bootstrap resolves the tenant from the Host header and calls `Database::setSchema()` once per
request; every inherited controller runs unmodified. Sprinkling `WHERE tenant_id = ?` would
reintroduce the forgotten-filter leak class this architecture exists to make impossible. A
missing `setSchema` fails **loud** ("table does not exist"), not silently with a leak.

### 2. No foreign key crosses the schema boundary, either direction
Public tables reference tenants only by `tenant_id`; tenant tables never FK to public. The lone
cross-reference (`admin_activity.support_session_id`) is a plain string by design. Payoff: every
tenant schema is independently dump/drop/restore-able. Cost accepted: no DB-enforced referential
integrity across the boundary; denormalized copies are reconciled by helper + nightly cron.

### 3. Stripe state is webhook-driven; never optimistic local writes
`subscriptions.local_status` and `tenants.plan_id` flip only on the enumerated webhook events,
after an INSERT-first dedup row (`billing_events` / tenant `stripe_webhook_events`). Handler runs
in a transaction; `processed_at` stamped after; mail after the transaction (Stripe's 10 s
deadline). The two Stripe planes — platform subscriptions vs. per-tenant Connect shops — never
cross.

### 4. State changes go through `transitionTo()` methods, not direct UPDATEs
`Tenant::transitionTo()`, `TenantDomain::transitionTo()`, `ShopConnect::transitionTo()`,
`SenderIdentity` transitions: validate against the state machine, write the audit row, fire
side-effects. Crons, webhooks, admin buttons, and operator tools all funnel through the same
method, so an invalid jump is unrepresentable.

### 5. Migrations are per-tenant and individually idempotent
Public migrations (`sql/public/`) and tenant migrations (`sql/migrations.postgres/`) each keep
their own ledger; every file is guarded (`IF NOT EXISTS`, `DO $$…$$`, `ON CONFLICT`) so it
re-applies harmlessly. `PlatformMigrationRunner` loops tenants with per-tenant try/finally
isolation — one broken tenant never blocks the fleet.

### 6. Three distinct auth keyspaces in one session
`platform_admin_id` (2FA-mandatory), per-tenant `admin_id`, anonymous shop customer — separate
session keys, separate tables, no shared user ids. Operator access to tenant admin goes only
through the audited `support_sessions` flow (`via_support=true` on every write, visible to the
tenant).

### 7. Custom-domain certs only issue for DNS-verified domains
Caddy's on-demand TLS asks `/caddy-ask`, which 200s only for hostnames in `tenant_domains` with
`DNS_VERIFIED`/`CERT_PROVISIONING`/`ACTIVE` **and** a live-enough owning tenant. This is the
guard against burning the Let's Encrypt rate limit with hostile domains pointed at the platform
IP: reaching `DNS_VERIFIED` requires completing a TXT ownership challenge.

## Key decisions (ADR-style summary)

| Decision | Choice | Rationale / rejected alternatives |
|---|---|---|
| Tenancy model | Postgres schema-per-tenant | Row-level `tenant_id` = one forgotten WHERE from a leak across ~100 framework-less controllers; ORM retrofit = bigger rewrite than the MySQL→Postgres port. Mirrors django-tenants pattern proven in the sibling project. |
| Database | Postgres (SaaS only; upstream stays MySQL) | First-class schemas + `search_path`; transactional DDL makes provisioning atomic. MySQL "schema"=database makes backup/GRANTs per-database ugly. |
| Edge | Caddy on-demand TLS | nginx+certbot needs per-domain config or restarts for runtime-added custom domains; Caddy issues off SNI with an app-controlled allowlist. |
| Keep the no-framework CMS shape | Yes — bootstrap-level middleware only | The point of schema-per-tenant is that application code stays single-tenant-shaped; controllers carry over unmodified from the fork base. |
| Async work | Cron (supercronic), no queue/Redis | Everything needed is periodic; per-tenant isolation handled by the `for_each_tenant` loop pattern. Queue re-evaluated only if a feature needs reliable async. |
| Sessions | Files → (at multi-VM scale) Postgres `UNLOGGED` table | Avoids operating Redis; session loss on crash = re-login, acceptable. |
| Uploads | `Storage` interface, PHP-proxied, S3-compatible backend chosen by `.env` | Centralized validation/resize; vendor (R2/B2/S3) is an operator decision, not a code decision. Presigned direct upload deferred until scale demands. |
| Email | AWS SES; shared identity for Free/Pro, per-tenant DKIM identity for Studio | Tenant-scoped reputation where it matters; SNS bounce/complaint loop feeds suppression + identity failure guards. |
| Payments | Stripe Billing (platform) + Stripe Connect direct charges (tenant shops) | Tenants' shop revenue must land on **their** account; platform fee is a plan column (`shop_application_fee_bps`, 0 today). |
| Monetization | URL + brand, not feature gates | Competitive frame is Squarespace, not WordPress.com; the free tier is a real product and every free site is a billboard (footer link). |
| Scaling | Single VM, vertical, until ~10K tenants | Operational simplicity; the split (edge / app / managed PG) changes zero application code when it comes. |
| Handle reuse | 90-day cooldown after release; renames redirect for 1 year first | Subdomain-takeover defense sized to third-party verification windows. |

## Genuinely open questions

Carried from the design docs — real, undecided, with documented leans:

- **Apex domains via fixed-IP A records**: deferred; would commit the platform to IP stability.
  Current answer is ALIAS-capable registrars or `www.` + redirect.
- **CDN / Cloudflare-managed certs for custom domains** ($0.10/domain/mo): evaluate only if
  Let's Encrypt rate limits ever become the bottleneck.
- **Same-tenant handle reclaim after rename**: shipped stricter than the design lean (no
  self-exemption inside the 1-year redirect window) — revisit on user complaints.
- **Postgres hosting**: self-managed on the VM now; managed (RDS/Crunchy) when PITR/replica
  operations justify it.
