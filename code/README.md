# Code Walkthroughs (developer)

> Mirrored from `design-docs/walkthroughs/code/` in the
> [`makerfolio-saas`](https://github.com/TitaniaAnn/makerfolio-saas) repo, with relative links
> rewritten to absolute GitHub URLs. The in-repo copy alongside the source is the living one;
> if the two drift, it wins.

How the makerfolio-saas codebase **works** — one walkthrough per subsystem, each grounded
in real `file:line` references so you can read the doc with the source open beside it.

These are *developer* docs (how the code is built). For *usage* guides (how to drive the
running app) see the sibling sets:

- **Platform-admin operator** runbooks — [`../README.md`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/walkthroughs/README.md)
- **Tenant owner** UI — [`../tenant/README.md`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/walkthroughs/tenant/README.md)
- **Public visitor** pages — [`../visitor/README.md`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/walkthroughs/visitor/README.md)

For the *why* behind the architecture, [ARCHITECTURE.md in this repo](../ARCHITECTURE.md),
[ARCHITECTURE.md](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/ARCHITECTURE.md) and the SaaS
invariants in [CLAUDE.md](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/CLAUDE.md); for data shapes, [MODELS.md](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/MODELS.md) wins.

## The walkthroughs

| # | Walkthrough | Covers | Key invariant |
|---|---|---|---|
| 01 | [Tenancy, bootstrap & routing](01-tenancy-bootstrap-routing.md) | Request lifecycle: Caddy → bootstrap → `TenantResolver` → `setSchema` → controller | #1 schema-per-tenant via `search_path` |
| 02 | [Auth & security](02-auth-and-security.md) | Three keyspaces, CSRF, hardening headers, CSP, rate limiting, TOTP, support sessions, roles | #6 three auth keyspaces |
| 03 | [Signup & provisioning](03-signup-and-provisioning.md) | Signup → verify → `Tenant::provision` → `site_published` gate; ownership transfer | #4 `transitionTo` state machines |
| 04 | [Billing — platform plane](04-billing-platform-plane.md) | Pro/Studio subscriptions, platform Stripe webhook, `COMING_SOON_PAID_PLANS` | #3 webhook-driven Stripe state |
| 05 | [Shop — Connect plane](05-shop-connect-plane.md) | Per-tenant storefront, Stripe Connect, the two webhook planes | #3 INSERT-first idempotency |
| 06 | [Email & deliverability](06-email-deliverability.md) | Mailer drivers (log/smtp/ses), verified-sender From/Reply-To, SES bounce webhook | verified-sender `From` |
| 07 | [Themes, content & rendering](07-themes-content-rendering.md) | CSS-var theme tokens, `PageText`, `PageSections`, branding, sample content | nonce'd `<style>`; no hardcoded strings |
| 08 | [Uploads, storage & images](08-uploads-storage-images.md) | `Storage` interface, local/S3, image crop/rotate/delete, downloadable files | `*_storage_key`; uploads PHP-exec lockdown |
| 09 | [Migrations & schema](09-migrations.md) | Fresh-install SQL, incremental runner, per-tenant fan-out, idempotency | #5 per-tenant idempotent migrations |
| 10 | [Custom domains & TLS](10-custom-domains-tls.md) | DNS verification, Caddy on-demand TLS, the `/caddy-ask` gate | #7 certs only for verified domains |

## Reading order

New to the codebase? Read **01** (the fork layer that makes everything else single-tenant-shaped),
then **02**, then whichever subsystem you're touching. The two Stripe planes (**04** platform vs
**05** Connect) are deliberately separate — don't conflate them.

## A note on accuracy

Every claim cites `file:line` as of the commit these were written against. Line numbers drift
as code changes — treat them as "look near here", and trust the surrounding prose + symbol names
over the exact line. If a walkthrough and the code disagree, the **code wins** and the walkthrough
is stale; fix it.
