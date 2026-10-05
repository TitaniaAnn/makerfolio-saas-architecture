# 01 — System context

## What makerfolio is

A hosted, multi-tenant version of a single-tenant PHP portfolio CMS. Any maker signs up, gets a
portfolio site at `<handle>.makerfolio.art` in minutes, and can optionally pay to point a custom
domain at the same site. Every tenant gets the **full** CMS feature set — portfolio with
multi-image galleries, Stripe-powered shop, events, announcements, downloadable templates,
theming, email templates, 2FA, activity log. Monetization is mostly on **brand, URL and
selling** (custom domain, no "Powered by" footer, own-site checkout), with content caps on Free.

### Plans

Tiers and prices are data, not code: `public.tiers` + `public.plans`, edited in platform-admin,
with `bin/configure-plans.php` as the declarative reconciler. The current lineup, with the
standing monthly prices that script targets (founding discounts run as Stripe coupons on top):

| Tier | Monthly | What it adds |
|---|---|---|
| Free | $0 | `<handle>.makerfolio.art`, capped content, "Powered by makerfolio" footer, no shop |
| Basic | $6 | Your own domain + auto TLS; a link-out shop catalog (`shop_links`: products that link to Etsy, print-on-demand, …) |
| Pro | $12 | Own-site checkout through the tenant's Stripe Connect account (`shop_checkout`), orders |
| Studio | $39 | Team users with roles (OWNER/EDITOR/CONTRIBUTOR), more domains, white-label email (per-tenant SES sender identity) |

Annual prices are 10× monthly. Exact caps live in the plan rows and are not repeated here.

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
— multi-tenancy lives entirely in bootstrap, routing, and storage layers. (The one later reach into
them was SEO, not tenancy: eleven public pages moved onto a shared `tenant_head()` partial so
canonical URLs and structured data come from one place.)

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
| Edge | Caddy 2 behind Cloudflare | Cloudflare proxies the apex + tenant subdomains (Caddy serves a CF origin cert); custom domains bypass Cloudflare and get **on-demand TLS** from Caddy; FastCGI to PHP-FPM. Client IP from `CF-Connecting-IP` only via a trusted proxy |
| Deploy | Docker Compose on a single Hetzner VM (Ubuntu 24.04) | Vertical scaling carries to ~10K tenants; see [07-operations](07-operations.md) |
| Cron | supercronic | 18 periodic jobs on 6 schedule lines; no queue/Redis — everything is cron + synchronous |
| Object storage | S3-compatible via a `Storage` interface (`LocalStorage` / `S3Storage`) | `STORAGE_DRIVER` env selects; R2/B2/S3 all work |
| Mail | AWS SES (`MAIL_DRIVER=ses`) | Platform identity for Free/Pro; per-tenant DKIM sender identities for Studio; SNS bounce/complaint webhook |
| Payments | Stripe Billing (platform plane) + Stripe Connect (tenant shop plane) | Two accounts/planes that never cross; see [05-billing](05-billing-and-payments.md) |
| Tests | PHPUnit 10 (~650 tests, DB-free via in-memory SQLite bootstrap) + 47 smoke scripts under `bin/` | Pure-logic unit tests; smokes cover DB-touching flows |

## Request classes

Three classes of inbound HTTPS request, classified by hostname:

1. **Marketing site** — `makerfolio.art/` (pricing, examples, signup). Public schema only; no tenant.
2. **Tenant on platform subdomain** — `annie.makerfolio.art/…`. Through Cloudflare; Caddy's
   origin cert covers it.
3. **Tenant on custom domain** — `anniespots.com/…` (Basic+). CNAME to `tenants.makerfolio.art`
   (a DNS-only record, so this traffic skips Cloudflare); cert issued on demand.

All three converge on the same PHP-FPM pool; `TenantResolver` (in bootstrap) does the
classification. See [03-routing-and-tls.md](03-routing-and-tls.md).

## What is deliberately out of scope

Plugin/theme marketplaces, A/B testing framework, mobile app, multi-language UI, per-user GDPR
export for shop customers (Stripe holds payment PII). The CMS is opinionated and finished; the
platform adds tenancy, billing, domains, and operations around it — not new CMS surface area.
