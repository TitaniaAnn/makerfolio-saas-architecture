# 01 — Tenancy, bootstrap & routing

**Audience:** developers working on makerfolio-saas.
**Scope:** the "fork layer" — how an unmodified single-tenant controller ends up serving the right tenant's data.

> Every web request requires `includes/bootstrap.php`, which at its tail calls `TenantResolver::resolve()`. The resolver maps the Host header to a tenant schema and calls `Database::setSchema()` (`SET search_path TO "<schema>", public`) before any controller query runs. Schema-per-tenant means the inherited controllers stay single-tenant-shaped: `SELECT * FROM piece` transparently hits `tenant_<id>.piece`. A forgotten `setSchema` fails loud (`relation does not exist`), never silently leaks another tenant's rows — that loud-failure property is the load-bearing security argument (see [ARCHITECTURE.md §1](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/ARCHITECTURE.md)).

## Map — the files
| File | Role |
| --- | --- |
| [`Caddyfile`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/Caddyfile) / [`Caddyfile.prod`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/Caddyfile.prod) | Front edge. Clean-URL `try_files`, security backstops, on-demand-TLS `ask` gate. Falls through to `/_apex-router.php`. |
| [`public/_apex-router.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/_apex-router.php) | Fallback entry for any path with no physical `*.php` at the doc root. |
| [`public/index.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/index.php) | Example inherited controller: requires bootstrap, then queries `piece`/`events`/… unqualified. |
| [`includes/bootstrap.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/bootstrap.php) | Autoload → `.env` → config → core classes → `Auth::start()` → hardening headers → `.php`-strip redirect → `TenantResolver::resolve()`. |
| [`includes/TenantResolver.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/TenantResolver.php) | Host → tenant/marketing/404/suspended/redirect decision; per-worker LRU; `setSchema`; minimal error renderers. |
| [`includes/Tenant.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/Tenant.php) | `findByHandle`, `isReservedHandle`, `active`, `provision`, `transitionTo`. |
| [`includes/Database.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/Database.php) | PDO singleton; `setSchema` / `resetSchema` (search_path). |
| [`includes/SaaSUrl.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/includes/SaaSUrl.php) | Build apex/tenant URLs; `redirectTarget` keeps `SITE_URL`-prefixed redirects on the current host. |
| [`public/caddy-ask/index.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/public/caddy-ask/index.php) | On-demand-TLS allowlist endpoint (detail in `10-custom-domains-tls.md`). |

## The flow
A request to `annie.makerfolio.art/portfolio`:

1. **Caddy terminates TLS, routes to PHP-FPM.** The platform vhost (`makerfolio.art, *.makerfolio.art`) matches; security backstops run first inside the `route` block — `@denied` (`Caddyfile.prod:36`) and `@uploadsPhp` (`Caddyfile.prod:37`) respond `404` before any handler. Clean URLs resolve via `php_fastcgi … try_files {path} {path}.php {path}/index.php /_apex-router.php` (`Caddyfile.prod:56-58`). `/portfolio` resolves to `public/portfolio.php`; an unmatched path falls through to `/_apex-router.php`.
2. **Entry script requires bootstrap.** Every controller's first line is `require_once …/includes/bootstrap.php` (`public/index.php:2`, `public/_apex-router.php:13`).
3. **bootstrap wires the world, in order.** Autoloader (`bootstrap.php:10`), then `.env` **before** `config/config.php` because config reads `$_ENV` at constant-definition time (`bootstrap.php:12-19`). Core + multi-tenant classes are `require_once`d (`bootstrap.php:20-68`), then `Auth::start()` (`bootstrap.php:70`).
4. **Hardening + `.php`-strip.** A per-request `CSP_NONCE` is minted (`bootstrap.php:92-94`) and the CSP / `Referrer-Policy` / `X-Frame-Options` headers are sent (`bootstrap.php:113-178`). A GET request for a `*.php` URL is `301`'d to its clean form (`bootstrap.php:461-472`) — GET-only, so POSTs and webhooks are untouched, and host-relative, so it stays on the current host.
5. **`TenantResolver::resolve()`** runs last (`bootstrap.php:478`). No-op when `PLATFORM_DOMAIN` is empty (self-host) (`TenantResolver.php:58-60`).
   - **Host normalize:** `currentHost()` lowercases and strips the port, handling IPv6 brackets (`TenantResolver.php:215-232`).
   - **Special paths:** `/sitemap.xml` + `/robots.txt` set schema but skip marketing dispatch (`TenantResolver.php:76-94`); apex-only prefixes `/platform-admin`, `/signup`, `/platform-webhook`, `/caddy-ask` `return` on apex and `404` on any other host (`TenantResolver.php:100-107`, `196-201`).
   - **Decision (cached):** `cacheGet` then `compute()` (`TenantResolver.php:109-113`). `compute()` classifies: apex/`www.apex` → `marketing`; `<handle>.<domain>` → reserved-handle check (`isReservedHandle`, `Tenant.php:50-58`) then `Tenant::findByHandle` (`Tenant.php:88-96`), with an old-handle 301 lookup against `public.handle_redirects` on a miss (`TenantResolver.php:266-282`); anything else → `public.tenant_domains` where `status = 'ACTIVE'` (`TenantResolver.php:293-300`).
6. **Dispatch on the decision** (`TenantResolver.php:115-169`):
   - `tenant` → status gate: `SUSPENDED` → `renderSuspended` (503); `PENDING_DELETION`/`DELETED` → `render404`; otherwise `Database::setSchema($t['schema_name'])` + record `currentTenantId` (`TenantResolver.php:119-129`), then the "coming soon" gate for unpublished sites (`isComingSoonGated`, `TenantResolver.php:48-54`, `136-139`).
   - `marketing` → `dispatchMarketing()` includes a file from a trusted path map under `public/marketing/` (`TenantResolver.php:378-412`).
   - `redirect_handle` → 301 to the new handle's subdomain preserving path+query (`TenantResolver.php:146-163`).
   - `notfound` → `render404`.
7. **`setSchema` points the connection.** `SET search_path TO "<schema>", public` after validating the name against `/^[a-z][a-z0-9_]*$/` (identifiers can't be bound as params) (`Database.php:51-59`). The trailing `, public` keeps `public.`-qualified platform queries working from tenant context.
8. **Control returns to the controller.** `resolve()` returns (it only `exit`s on marketing/404/suspended/redirect). `public/portfolio.php` runs its inherited, unqualified queries against the now-selected schema. `public/index.php:6` (`SELECT … FROM piece …`) is the canonical example.

## Invariants & gotchas
- **Tenancy is `search_path`, never `WHERE tenant_id`.** A missing `setSchema` leaves `search_path = public`; app tables aren't in `public`, so the query errors `42P01 relation does not exist` — loud, not a leak. Proven by `bin/tenant-isolation-smoke.php:71-83`. (CLAUDE.md invariant 1; `Database.php:51-59`.)
- **`resolve()` runs `exit`, not `throw`, on every non-tenant branch.** `marketing`/`notfound`/`suspended`/`redirect_handle` all `exit` inside `resolve()` (`TenantResolver.php:143-168`). Reaching `_apex-router.php:15` therefore means the resolver did **not** exit → we are in tenant context with an unmatched path → render the branded tenant 404 (`_apex-router.php:17-18`).
- **Apex-only paths must not be reachable through a tenant host.** `/caddy-ask`, `/platform-admin`, `/signup`, `/platform-webhook` `404` on subdomains (`TenantResolver.php:196-201`). This is defence-in-depth for the cert gate on top of Caddy binding `caddy-ask` to `localhost:8080` (`Caddyfile.prod:111-116`).
- **DNS_VERIFIED / CERT_PROVISIONING domains are NOT routed.** `compute()` only routes `tenant_domains` rows in `ACTIVE` (`TenantResolver.php:298`); pre-cert states 404 rather than serving plaintext before TLS is ready. The `/caddy-ask` allowlist is wider (`DNS_VERIFIED`/`CERT_PROVISIONING`/`ACTIVE`, `caddy-ask/index.php:63`) — issuing a cert and routing traffic are deliberately separate gates.
- **Reserved handles route to marketing, never tenants.** `RESERVED_HANDLES` + all 2-letter strings + the `handle_reservations` table (`Tenant.php:50-58`); `compute()` returns `marketing` for them (`TenantResolver.php:255-258`) so a tenant can never claim `admin`, `www`, etc.
- **The LRU is per-worker and TTL-bounded (60s).** `cachePut` stores `['data'=>…, 'exp'=>time()+60]`, evicting the oldest at 128 entries (`TenantResolver.php:18-19`, `359-366`). `Tenant::transitionTo`/`hardDelete` call `invalidateHost`/`invalidateTenant` for same-worker immediacy (`TenantResolver.php:319-344`); other workers lag up to the TTL, which is safe because `setSchema` on a dropped schema fails loud and `Auth::requireLogin` guards the suspension surface.
- **`redirect()` rewrites `SITE_URL`-prefixed targets onto the current host.** Inherited controllers redirect to `SITE_URL . '/path'`, but `SITE_URL` is the apex while the request runs on a tenant host — so `redirect()` routes the target through `SaaSUrl::redirectTarget` (`bootstrap.php:198-208`). That helper passes external URLs (Stripe) and same-host/self-host requests through unchanged, and only swaps the host for apex-prefixed targets on a different host (`SaaSUrl.php:35-45`). Without it, every post-action redirect on a tenant would jump to the apex "No site here".
- **CSRF failure follows the referer only if same-origin with the *request* host, not `SITE_URL`.** In SaaS the request host is a tenant subdomain that doesn't match `SITE_URL`; the open-redirect guard compares against `HTTP_HOST` (`bootstrap.php:240-258`). (CLAUDE.md security model.)
- **`.env` loads before `config.php`.** Config reads `$_ENV` at constant-definition time; CLI scripts must replicate the order (`bootstrap.php:12-19`; see `bin/tenant-isolation-smoke.php:19-24`).

## Tests & verification
- [`bin/tenant-isolation-smoke.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/tenant-isolation-smoke.php) — the core proof: writes a probe in `cynthia`, asserts `annie` sees zero rows, and that an unqualified query with no `setSchema` fails loud (`42P01`). Then drives `resolve()` with a tenant Host and asserts `search_path` includes the right schema. Pgsql-only; run inside the web container.
- [`tests/TenantResolverTest.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/tests/TenantResolverTest.php) — pure pieces via reflection: host parsing (port strip, IPv6 brackets), `isApexOnlyPath`, LRU TTL/cap. DB- and exit-crossing branches are deliberately out of unit scope.
- [`bin/tenant-domain-routing-smoke.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/tenant-domain-routing-smoke.php), [`bin/marketing-smoke.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/marketing-smoke.php), [`bin/caddy-ask-smoke.php`](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/bin/caddy-ask-smoke.php) — custom-domain routing, apex marketing dispatch, and the cert gate end-to-end.

## See also
- [`02-auth-and-security.md`](./02-auth-and-security.md) — the three auth keyspaces, CSRF, hardening headers, support sessions.
- [`09-migrations.md`](./09-migrations.md) — per-tenant idempotent migrations and `PlatformMigrationRunner`.
- [`10-custom-domains-tls.md`](./10-custom-domains-tls.md) — `tenant_domains` state machine and the `/caddy-ask` gate.
- [design-docs/ARCHITECTURE.md](https://github.com/TitaniaAnn/makerfolio-saas/blob/main/design-docs/ARCHITECTURE.md) §1–2 (tenancy + routing), §6 (security architecture).
