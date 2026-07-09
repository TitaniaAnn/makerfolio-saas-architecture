# 01 — System context

## What makerfolio is

A hosted, multi-tenant version of a single-tenant PHP portfolio CMS. Any maker signs up, gets a
portfolio site at `<handle>.makerfolio.art` in minutes, and can optionally pay to point a custom
domain at the same site. Every tenant gets the **full** CMS feature set — portfolio with
multi-image galleries, Stripe-powered shop, events, announcements, downloadable templates,
theming, email templates, 2FA, activity log — with no feature-crippled free tier. Monetization is
on **brand and URL** (custom domain, no "Powered by" footer), not feature gates.

### Plans (as shipped)

| Plan | Price | Key differences |
|---|---|---|
| Free | $0 | `<handle>.makerfolio.art`, 30 pieces, 5 photos/piece, 1 GB storage, "Powered by makerfolio" footer, no shop, no custom domain |
| Pro | $8/mo or $80/yr | Custom domain + auto TLS, unlimited content, 20 GB, shop (Stripe Connect), footer removed, own-domain email sender fallback |
| Studio | $29/mo or $290/yr | Everything in Pro + team users with roles (OWNER/EDITOR/CONTRIBUTOR, up to 10), 5 custom domains, 100 GB, white-label email (per-tenant SES sender identity) |

## Actors

- **Visitor / shop customer** — anonymous; browses a tenant's public site, checks out via the
  tenant's Stripe Connect account. No account; identified by email at checkout.
- **Tenant admin** — the maker (and, on Studio, their team). Authenticates per-tenant at
  `<tenant-host>/admin/`; local password (email or username) + optional GitHub/Google OAuth +
  optional TOTP 2FA.
- **Platform admin** — the operator's team. Authenticates at `/platform-admin/` against a separate
  `public.platform_admin_users` table; **2FA mandatory**. Operates the tenant fleet: suspend /
  restore / refund / migrate / audited "log in as tenant".

## Lineage and repos

The SaaS was **forked** from the single-tenant `pottery-profile-cms` (MySQL + Apache), which
continues unchanged as the self-host product. The fork's whole design bet: with schema-per-tenant,
the ~100 inherited page controllers under `public/` and `public/admin/` carry over **unmodified**
— multi-tenancy lives entirely in bootstrap, routing, and storage layers.

| Repo | Role |
|---|---|
| `makerfolio-saas` | This system. Postgres + Caddy + Docker, multi-tenant. |
| `pottery-profile-cms` | Upstream fork base. Stays MySQL/Apache/single-tenant; the self-host product. |
| `public-studio-manager` | Pattern reference (Django + django-tenants). The SaaS deliberately mirrors its proven conventions: schema-per-tenant via `search_path`, `for_each_tenant()` isolation, webhook-as-source-of-truth Stripe handling, `transitionTo()` state machines, SES bounce handling. |

## Tech stack

| Layer | Choice | Notes |
|---|---|---|
| Language / runtime | PHP 8.2, PHP-FPM | Server-rendered, **no framework, no build step**, vanilla JS/CSS |
| Code style | Procedural page controllers + static helper classes in `includes/` | No router: Caddy maps clean URLs to `public/*.php`; every entry point requires `includes/bootstrap.php` |
| Database | Postgres 16 | One database; `public` schema (platform) + `tenant_<id>` schema per tenant. `Database` is a PDO singleton with parameterized `query/fetchOne/fetchAll/insert/update/delete` + `transaction(callable)` |
| Edge | Caddy 2 | TLS termination, wildcard cert for `*.makerfolio.art`, **on-demand TLS** for custom domains, FastCGI to PHP-FPM. Cloudflare optionally in front (real client IP via `CF-Connecting-IP` with CIDR trust) |
| Deploy | Docker Compose on a single Hetzner VM (Ubuntu 24.04) | Vertical scaling carries to ~10K tenants; see [07-operations](07-operations.md) |
| Cron | supercronic | ~16 periodic jobs; no queue/Redis — everything is cron + synchronous |
| Object storage | S3-compatible via a `Storage` interface (`LocalStorage` / `S3Storage`) | `STORAGE_DRIVER` env selects; R2/B2/S3 all work |
| Mail | AWS SES (`MAIL_DRIVER=ses`) | Platform identity for Free/Pro; per-tenant DKIM sender identities for Studio; SNS bounce/complaint webhook |
| Payments | Stripe Billing (platform plane) + Stripe Connect (tenant shop plane) | Two accounts/planes that never cross; see [05-billing](05-billing-and-payments.md) |
| Tests | PHPUnit 10 (420+ tests, DB-free via in-memory SQLite bootstrap) + 45+ `bin/*-smoke.php` scripts | Pure-logic unit tests; smokes cover DB-touching flows |

## Request classes

Three classes of inbound HTTPS request, classified by hostname:

1. **Marketing site** — `makerfolio.art/` (pricing, examples, signup). Public schema only; no tenant.
2. **Tenant on platform subdomain** — `annie.makerfolio.art/…`. Covered by the wildcard cert.
3. **Tenant on custom domain** — `anniespots.com/…` (Pro+). CNAME to `tenants.makerfolio.art`;
   cert issued on demand.

All three converge on the same PHP-FPM pool; `TenantResolver` (in bootstrap) does the
classification. See [03-routing-and-tls.md](03-routing-and-tls.md).

## What is deliberately out of scope

Plugin/theme marketplaces, A/B testing framework, mobile app, multi-language UI, per-user GDPR
export for shop customers (Stripe holds payment PII). The CMS is opinionated and finished; the
platform adds tenancy, billing, domains, and operations around it — not new CMS surface area.
