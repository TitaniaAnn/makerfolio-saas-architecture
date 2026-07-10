# Architecture

This document is the long-form companion to the [README](README.md).
The README tells you *what* the architecture is and where each piece
lives. This document tells you *why* — what problem each decision
solves, what alternatives were considered, and where the seams are
that future work would extend.

The nine decisions below are roughly ordered from most foundational
to most product-facing. The earlier ones are the ones the rest depend
on; the later ones are the ones that would be easiest to swap out.

Subsystem-organized reference lives in [docs/](docs/); code-level
walkthroughs with `file:line` references live in [code/](code/).

---

## 1. Schema-per-tenant tenancy via `search_path`

### The problem

The product is a hosted version of an existing single-tenant PHP CMS
with roughly a hundred self-contained page controllers, each writing
its own SQL, with no framework and no ORM. Multi-tenancy has to be
added without turning every one of those controllers into a
maintenance hazard.

The standard answer — a `tenant_id` column on every table and a
`WHERE tenant_id = ?` on every query — has a catastrophic failure
mode in exactly this codebase: the first time a developer forgets the
clause on a page that lists portfolio pieces, every tenant's data is
visible to every other tenant. With ~100 files of hand-written SQL
and no compiler help, "forgets" is a *when*, not an *if*.

The architectural problem: how do you make cross-tenant leakage
impossible at the SQL layer rather than merely discouraged at the
code-review layer?

### The decision

One Postgres schema per tenant (`tenant_<id>`), each containing a
full copy of the CMS tables. The platform's own tables live in the
`public` schema. On every request, `bootstrap.php` resolves the
tenant from the `Host` header and runs
`SET search_path TO "tenant_<id>", public` exactly once
(`Database::setSchema()`). Every subsequent query is automatically
scoped: the inherited controller's `SELECT * FROM piece`
transparently hits `tenant_42.piece`.

The failure mode inverts. A forgotten `setSchema` doesn't leak — it
throws `relation "piece" does not exist`, loudly, on the first
query. Loud failure instead of silent leak is the load-bearing
security property of the whole design.

Two supporting choices ride along. Tenant resolution results are
cached per-worker (60 s LRU) so the added cost is one lookup per
request. And per-tenant Postgres roles exist with USAGE only on
their own schema — used for emergency DBA work and the support-login
flow, *not* by the app's connection pool, because poolers don't
reset roles on connection reuse without `DISCARD ALL`, which defeats
prepared-statement caching.

### Why not the alternatives

**Row-level `tenant_id`** — covered above: one forgotten WHERE
clause from a breach, forever, across a codebase designed before
multi-tenancy existed.

**An ORM that enforces scoping** — there is no ORM in the inherited
codebase. Bolting one on (Doctrine, Eloquent) is a far larger
rewrite than the actual port (DB driver + bootstrap middleware +
migration runner), and the rewrite itself would churn every
controller — the thing the design is trying to avoid.

**MySQL databases-as-schemas** — the CMS was MySQL, so staying put
was tempting. But MySQL's "schema" and "database" are synonyms, and
per-tenant databases make backups, GRANTs, and replication
per-database problems. Postgres's first-class schemas (many schemas,
one database, switchable via `search_path`) are the clean fit — and
the pattern was already proven in production by a sibling project
built on django-tenants. The SaaS moved to Postgres; the self-host
product stays MySQL.

### Where the seams are

Postgres catalog performance degrades somewhere around 10K–50K
schemas — three to four orders of magnitude beyond the product's
target scale, but a real ceiling. Past it, the design would shard
tenants across database clusters (the tenant directory already maps
tenant → schema, so it can map tenant → cluster + schema).

Cross-tenant aggregate queries are deliberately awkward. The answer
shipped is a nightly `usage_rollups` snapshot table in the public
schema, so operator dashboards never scan tenant schemas live.

### Verified by

`bin/tenant-isolation-smoke.php` in the product repo provisions two
tenants and asserts neither can see the other's rows;
`tests/TenantResolverTest.php` pins the host-classification logic.
Walkthrough: [code/01-tenancy-bootstrap-routing.md](code/01-tenancy-bootstrap-routing.md).

---

## 2. Fork the CMS; multi-tenancy lives in the bootstrap, not the controllers

### The problem

The single-tenant CMS is a finished, opinionated product that must
keep shipping unchanged for self-hosters. The SaaS needs the same
feature set. Maintaining two diverging codebases doubles every bug
fix; rewriting the CMS "properly" for the SaaS throws away years of
hardening.

### The decision

Fork the CMS and confine every multi-tenant concern to layers the
controllers never see: the bootstrap (tenant resolution +
`setSchema`), the edge (Caddy instead of Apache), the DB dialect
(Postgres port), and storage (a `Storage` interface). The inherited
controllers under `public/` and `public/admin/` carry over
**unmodified** — that is the measurable success criterion of
decision 1, not a side effect.

The fork keeps the CMS's structural conventions as contracts: every
entry point requires `bootstrap.php`; helpers like `e()`, `flash()`,
`setting()`, `csrf_verify()` work identically (they just operate
against the now-selected schema); admin pages keep the same CSRF,
auth-gate, and hardening-header discipline. New SaaS-layer code
(platform admin, signup, billing, domains) is written in the same
procedural-controllers-plus-static-helpers style, so there is one
idiom in the codebase, not two.

### Why not the alternatives

**A shared library/monorepo serving both products** — the products
diverge exactly at the layers that changed (Apache vs Caddy, MySQL
vs Postgres, local disk vs S3). A shared core would force
abstraction seams through code that otherwise never changes, to
serve two deployments with different risk profiles.

**Rewrite on a framework** — a Laravel/Symfony rewrite makes the
multi-tenant story *more* conventional but restarts the reliability
clock on a hundred already-hardened controllers, for no user-visible
gain.

### Where the seams are

Drift with the upstream fork base is one-directional by policy: the
upstream stays untouched, and the SaaS cherry-picks nothing back.
The cost is that upstream improvements arrive by manual port. The
`$isSaaS` branch points (mailer senders, storage writers, backup
page) are the places where the two products' behavior intentionally
differs — each is small and grep-able.

### Verified by

The product repo's test suite runs the inherited pure-logic tests
unchanged next to the SaaS-layer ones — one suite, one idiom.
Walkthroughs: [code/01](code/01-tenancy-bootstrap-routing.md),
[code/07](code/07-themes-content-rendering.md).

---

## 3. Idempotent per-tenant migrations with failure isolation

### The problem

Schema changes must now apply to N schemas, not one. Tenants are
provisioned at different times, so schemas are at different
versions. A migration that fails on one tenant must not strand the
other N−1. And a migration ledger row can be lost (restored dump,
manual surgery) without the schema change itself being lost —
re-applying must be harmless.

### The decision

Migrations are plain SQL files, numbered, in two universes: platform
migrations (`sql/public/NNN_*.sql`) with a ledger in
`public.schema_migrations`, and tenant migrations
(`sql/migrations.postgres/NNN_*.sql`) with a ledger **inside each
tenant schema** — every tenant tracks its own apply state, so a new
migration can roll out tenant-by-tenant.

Every file is *individually idempotent*: guarded with
`CREATE TABLE IF NOT EXISTS`, `DO $$ … $$` existence checks, and
`INSERT … ON CONFLICT`, so re-running any file against any state is
a no-op. The guard lives in each migration rather than the runner
because SQL DDL variance (column renames, index swaps, data
backfills) is too wide for a runner-level catch to classify safely.

`PlatformMigrationRunner` fans out: apply public migrations, then
loop active tenants applying the per-tenant runner inside a
try/finally per tenant. One broken tenant records its error and the
loop continues; the operator UI shows per-tenant results with retry
buttons. Fresh tenants skip the incremental path entirely — 
provisioning applies the canonical `init` schema (with the ledger
pre-seeded) inside one transaction, which Postgres's transactional
DDL makes atomic: a failed provision rolls back the
`CREATE SCHEMA` too.

### Why not the alternatives

**A migration framework** (Doctrine Migrations, Phinx) — adds a
dependency and an abstraction over what is, in this codebase, a
30-line runner reading numbered SQL files. The multi-tenant fan-out
would still have to be written by hand; the framework saves nothing
where the actual risk is.

**One global ledger for all tenants** — breaks tenant-by-tenant
rollout and makes "this one tenant failed migration 27" an
inconsistent state instead of a recorded, retryable fact.

**Runner-level idempotency (catch "already exists")** — works for
the narrow ALTER/CREATE cases but silently mis-classifies richer
failures (a data backfill that half-ran). Per-file guards keep the
judgment where the context is.

### Where the seams are

Idempotency is enforced by convention and review, not tooling — the
same trade the universal-columns convention makes in the sibling
mobile app's architecture. A CI job that replays every migration
twice against a fresh schema would mechanize it; the product repo's
`pg-smoke` clear-and-replay loop covers the fresh-install half of
that today.

### Verified by

`bin/platform-migrations-smoke.php` (fan-out + isolation),
`bin/pg-smoke.php` (clear-and-replay), `tests/MigrationRunnerTest.php`
in the product repo. Walkthrough: [code/09-migrations.md](code/09-migrations.md).

---

## 4. Stripe state is webhook-driven — never optimistic local writes

### The problem

Billing state has two writers: the app (user clicked "upgrade") and
Stripe (payment actually succeeded, failed, retried, expired). Any
design where the app optimistically flips its own subscription state
eventually disagrees with Stripe — a checkout abandoned after the
local write, a card declined after the redirect, a subscription
cancelled from Stripe's dashboard directly. The disagreement is
always discovered by a paying customer.

Webhooks retry, deliver concurrently, and arrive out of order, so
"just handle the webhook" has its own failure modes: double-handling
a retried event (double refund, double email) or crashing mid-handle
and never finishing.

### The decision

Local billing state (`subscriptions.local_status`,
`tenants.plan_id`) changes **only** in webhook handlers, on an
enumerated list of events. The app's role in an upgrade is to create
the Checkout session and wait; the webhook flips the plan.

Every webhook endpoint follows the same idempotency contract,
inherited from the CMS's shop webhook and reused verbatim:

1. **INSERT the event id into a dedup ledger first** — the unique
   key serializes concurrent deliveries; a duplicate INSERT means
   "already handled or being handled", short-circuit 200.
2. Run the handler inside a DB transaction.
3. Stamp `processed_at`. A crash between 2 and 3 leaves the row
   unstamped, so Stripe's retry re-runs the handler against a
   rolled-back transaction — exactly once overall.
4. Send mail **after** the transaction, so a slow SMTP call can't
   blow Stripe's 10-second delivery deadline and trigger spurious
   retries.

A reconciliation cron (`stripe-dunning-sync`, every 15 min) sweeps
tenants in the dunning window as a belt-and-braces backstop — it
converges state, it never originates it.

### Why not the alternatives

**Optimistic local writes + webhook confirmation** — the
inconsistency window is exactly when users are watching (they just
paid). The sibling studio-management project tried it and converged
on webhook-as-source-of-truth; this design started there.

**Polling Stripe instead of webhooks** — turns a push contract with
delivery guarantees into a pull loop with rate limits, and still
needs all the same idempotency machinery for the poll results.

**A queue between webhook receipt and handling** — adds
infrastructure to solve ordering/retry problems the
INSERT-first-ledger already solves at the database layer.

### Where the seams are

The dedup ledger grows forever by design (it doubles as the billing
audit trail); pruning policy is an operator decision, not code.
Stripe API version is explicitly pinned — an SDK upgrade and the
pin get reviewed together (the product did exactly this across a
four-major-version SDK bump with zero wire-shape change).

### Verified by

`bin/billing-webhook-smoke.php` (dedup, crash-retry, out-of-order),
`tests/SubscriptionTest.php` (status mapping) in the product repo.
Walkthrough: [code/04-billing-platform-plane.md](code/04-billing-platform-plane.md).

---

## 5. Two Stripe planes: platform Billing vs. tenant Connect

### The problem

Money moves in two unrelated directions. Tenants pay the platform
for subscriptions. Buyers pay *tenants* through tenant shops. If
both flows run on one Stripe account, every tenant's shop revenue
lands in the platform's balance — which is wrong legally
(merchant-of-record), financially (payouts), and structurally (one
tenant's fraud freezes everyone's money).

### The decision

Two fully separate planes that never cross:

- **Platform plane**: the operator's own Stripe account charges
  tenants for Pro/Studio. State in `public.subscriptions`; webhook
  at `/platform-webhook/stripe/`.
- **Connect plane**: each tenant onboards their **own** connected
  account (Standard or Express). Shop checkouts are **direct
  charges** on the tenant's account (`stripe_account` header +
  `application_fee_amount`), so the tenant is merchant of record and
  the platform's cut is a per-plan basis-points column (0 today —
  turning on a platform fee is a one-value edit, not a code change).

Connect events arrive keyed by connected-account id with no Host
header, so they can't be tenant-resolved by hostname: a single
platform endpoint (`/platform-webhook/connect/`) resolves
`acct_xxx → tenant` via a unique index, then `setSchema`, then runs
the same INSERT-first dedup contract from decision 4 — with the
dedup ledger in the *tenant's* schema, because shop events are
tenant data.

The consequence of the separation: a tenant losing their Connect
onboarding cannot affect their subscription, and the platform
losing its Stripe context cannot stop tenants taking orders.

### Why not the alternatives

**One account, `transfer_data` destination charges** — makes the
platform merchant of record for every tenant's pottery sale:
platform handles disputes, refunds, and tax for goods it never saw.

**Payment facilitation without Stripe Connect** — a regulatory
regime, not a feature.

**Requiring tenants to paste their own API keys** (the self-host
model) — works for one self-hosted install; at SaaS scale it means
storing raw secret keys for every tenant and losing webhook
routing entirely. Connect exists to solve exactly this.

### Where the seams are

`shop_application_fee_bps` is seeded 0 on every plan — the
monetization lever exists but has never fired in production; the
first non-zero value should get a deliberate rollout. Express
accounts put more onboarding UX on the platform than Standard;
both are supported, and the mix is a product decision the
architecture doesn't constrain.

### Verified by

`tests/ShopConnectTest.php` (24 cases: status derivation, gating,
fee math), `bin/connect-webhook-smoke.php`, and a live-verified
connected-account checkout in the product repo. Walkthrough:
[code/05-shop-connect-plane.md](code/05-shop-connect-plane.md).

---

## 6. State machines behind `transitionTo()`

### The problem

Tenants, custom domains, Connect accounts, and sender identities
all carry status columns with real lifecycle rules (a suspended
tenant can be restored; a deleted one cannot; a domain can't jump
from DISABLED straight to ACTIVE without re-verification). Those
statuses are written from many places — admin buttons, webhooks,
half a dozen crons, operator tools. Scattered `UPDATE … SET status`
statements mean every writer re-implements (or forgets) the rules,
and nobody records why a transition happened.

### The decision

Every stateful entity exposes one mutation path:
`transitionTo($newStatus, $context)`. The method validates the
transition against the allowed-edges map, writes the audit row, and
fires the side effects that belong to the edge (suspension email,
Stripe cancel on deletion, cert-gate implications). Crons, webhook
handlers, and UI buttons all call the same method; an invalid jump
is unrepresentable rather than merely discouraged.

The tenant lifecycle
(`PENDING_VERIFICATION → ACTIVE ⇄ GRACE → SUSPENDED →
PENDING_DELETION → DELETED`, with a tombstone after DELETED for the
90-day handle cooldown) and the eight-state domain machine are the
two big instances; Connect status and sender-identity status follow
the same shape at smaller scale.

### Why not the alternatives

**A state-machine library** — the runtime logic is an edges map and
a foreach of side effects; a dependency would be larger than the
code it replaces (the same call the sibling mobile app's
architecture makes about workflow libraries).

**Database triggers** — enforce edges but hide side effects in the
database where they're hard to review, and cross-schema triggers
would violate the isolation rules of decision 1.

**Discipline alone** — was already failing in the sibling project
before it adopted the same pattern; "everyone remembers the rules"
does not survive the sixth writer.

### Where the seams are

Side effects fire in-process after the row write; a crash between
write and side effect (email not sent) is possible and accepted —
the audit row makes it visible, and effects are written to be safe
to re-fire manually. If an effect ever becomes must-not-drop, the
edge should enqueue-then-execute instead (there is no queue today;
see decision 9).

### Verified by

`tests/TenantTest.php`, `tests/TenantDomainTest.php` (edge
validation), and the lifecycle-cron smokes in the product repo.
Walkthroughs: [code/03](code/03-signup-and-provisioning.md),
[code/10](code/10-custom-domains-tls.md).

---

## 7. On-demand TLS gated by `/caddy-ask`

### The problem

Pro tenants bring custom domains at runtime. Each needs a
certificate, issued without an operator touching config, renewed
forever, and — critically — *not* issuable by strangers: anyone can
point a domain's DNS at the platform's IP, and an attacker who
points ten thousand at it can burn the Let's Encrypt rate limits
for everyone.

### The decision

Caddy's `on_demand_tls` issues certs at first SNI hit — zero
per-domain config, zero reloads — but only after asking an
internal-only app endpoint (`/caddy-ask`) whether the hostname is
allowed. The endpoint returns 200 only for hostnames in
`tenant_domains` whose status says DNS verification completed
(`DNS_VERIFIED` / `CERT_PROVISIONING` / `ACTIVE`) **and** whose
owning tenant is alive (ACTIVE/GRACE, or within the first 30 days
of SUSPENDED — after that the 404 lets certs lapse, so lapsed
accounts stop consuming rate-limit headroom).

The only path into `DNS_VERIFIED` is completing a TXT-record
ownership challenge (`_makerfolio-verify.<host>`), which requires
controlling the domain's DNS — the one thing the attacker with a
pointed CNAME doesn't have.

Because Caddy runs with its admin API off, cert issuance progress
is *observed*, not queried: a monitoring cron performs an SNI
handshake probe against provisioning domains and advances the state
machine (decision 6) when a live cert appears. A daily probe of all
active domains catches renewal failures.

### Why not the alternatives

**nginx + certbot** — per-domain config files or reloads for every
runtime-added domain; the operational cost this decision exists to
eliminate.

**A wildcard-only design** — covers `*.makerfolio.art` (and is used
for it) but cannot cover customer-owned domains by definition.

**Cloudflare SaaS-for-SaaS managed certs** ($0.10/domain/mo) — the
documented escape hatch if Let's Encrypt rate limits ever become
the binding constraint; not needed at current scale.

### Where the seams are

Apex domains can't take CNAMEs; the shipped answer is
ALIAS-capable registrars or `www.` + redirect. Publishing fixed A
records would close the gap at the cost of freezing the routing
tier's IPs — deliberately deferred. The TLS probe treats "cert
visible" as success; a cert served by something *other* than our
Caddy (misrouted DNS) would fool it — the DNS verification step is
what makes that scenario contrived.

### Verified by

`bin/caddy-ask-smoke.php` (allowlist matrix including the
suspended-tenant cutoffs), `bin/tenant-domain-routing-smoke.php`,
`tests/TenantDomainTest.php`, `tests/DomainVerifierTest.php` in the
product repo. Walkthrough:
[code/10-custom-domains-tls.md](code/10-custom-domains-tls.md).

---

## 8. Three auth keyspaces and audited support sessions

### The problem

Three unrelated kinds of people use the same PHP process: the
platform operator, each tenant's admins, and anonymous shop buyers.
The operator additionally needs to *become* a tenant admin
occasionally (support), which is the single most abusable
capability in the system.

### The decision

Three non-overlapping session keyspaces in one PHP session:
`platform_admin_id` (against `public.platform_admin_users`, TOTP
2FA **mandatory** — login refuses to complete without enrollment),
`admin_id` (against the tenant schema's `admin_users` — ids are
per-tenant and meaningless across tenants), and nothing at all for
buyers. One browser can hold all three simultaneously without
interaction. Session state is tenant-checked, so a cookie minted on
one tenant's host is inert on another's.

"Log in as tenant" is a first-class audited flow, not a shared
password: starting one requires a free-text reason, creates a
`support_sessions` row with a 1-hour expiry, writes a visible entry
in the *tenant's own* activity log, banners every admin page red
for the duration, and stamps `via_support=true` plus the operator's
id on every write. The tenant can read exactly what support did and
when. Support sessions never consume a tenant admin seat.

The inherited per-tenant hardening (CSRF on every mutation,
rate-limited logins, strict CSP with zero inline script/style,
trusted-proxy-gated client IPs) carries over as contracts — decision
2 means the SaaS inherits the CMS's security posture rather than
re-deriving it.

### Why not the alternatives

**One users table with roles** — puts platform-operator credentials
and tenant credentials in the same namespace, so one SQL-injection
or session bug away from privilege escalation across the boundary
that matters most. Separate tables in separate schemas make the
boundary structural.

**Operator access via a shared tenant password** — unauditable,
unrevokable, and indistinguishable from the tenant's own actions;
the support-session flow exists to make operator access *more*
visible than normal access, not less.

**SSO/IdP for tenant admins** — makers sign up with an email
address; an identity provider is enterprise friction the audience
doesn't have. OAuth (GitHub/Google) is offered as a convenience on
top of local auth.

### Where the seams are

Support-session expiry is one hour, hard-coded to the "debug one
thing" use case; long investigations mean re-starting with a fresh
reason, which is the intended friction. Buyer identity is an email
at checkout; if buyer accounts ever exist, they are a fourth
keyspace, not an extension of any current one.

### Verified by

`tests/SupportSessionTest.php`, `tests/RoleTest.php`,
`tests/PlatformAuthTest.php`, `bin/support-session-smoke.php`,
`bin/role-gates-smoke.php` in the product repo. Walkthrough:
[code/02-auth-and-security.md](code/02-auth-and-security.md).

---

## 9. Boring operations: one VM, cron not queue, swappable edges

### The problem

A solo-operated SaaS dies of operational surface area long before
it dies of scale. Every additional moving part — a queue, a cache
tier, a second VM — is something that pages at 3 a.m. The
architecture has to pick which complexity is load-bearing and
refuse the rest.

### The decision

One Hetzner VM running Docker Compose: Caddy, PHP-FPM, Postgres,
and a supercronic cron container. All async work is periodic crons
(~16 of them: domain verification, cert probes, lifecycle sweeps,
dunning sync, rollups, sender-identity sweeps), each iterating
tenants with the same per-tenant isolation as the migration runner.
There is no queue, no Redis, no worker tier.

The reliability answer for crons isn't more infrastructure — it's
*observability of the crons themselves*: every job runs through a
heartbeat wrapper recording into a `cron_runs` table, surfaced as a
dead-man's-switch board in the operator UI, alongside webhook
ledgers with stuck-event drill-ins, an outbound-mail ledger,
`/healthz` + Docker healthchecks, and a daily operator digest
email. State-changing sweeps write what they did to the audit log,
so "what did last night's sweep do" is an admin-UI question, not an
SSH question.

The pieces that *will* change are behind deliberate seams: storage
is a `Storage` interface (`local` vs any S3-compatible bucket —
the vendor is an `.env` choice, not a code choice), mail is a
driver (`log`/`smtp`/`ses`), the CDN is "Cloudflare in front of
Caddy, a DNS change away", and the multi-VM split (edge / app /
managed Postgres) changes zero application code because the app is
stateless beyond Postgres + object storage.

### Why not the alternatives

**A queue (Redis/SQS) from day one** — nothing in the product needs
sub-minute async or reliable exactly-once jobs; the two contracts
that *look* queue-shaped (webhook retry, migration fan-out) are
solved at the database layer where they're transactional for free.

**Kubernetes / multi-VM from day one** — the scaling story
("vertical to ~10K tenants") is measured, not hoped; the split is
designed and costs nothing until it's needed.

**Serverless PHP** — cold starts and per-request pricing fit spiky
workloads; this is a steady low-volume multi-tenant monolith with a
database at its center, the workload serverless fits worst.

### Where the seams are

The cron model's floor is 1-minute granularity and no retries
within a period — acceptable for everything shipped; the first
feature needing reliable sub-minute async (the docs' example:
video transcoding) forces the queue decision. Sessions are
file-backed on the single VM; the designed evolution is a Postgres
`UNLOGGED` table at multi-VM scale, skipping Redis on purpose.
Postgres itself is the real scaling boundary, and "move to managed
Postgres" is the documented first split.

### Verified by

`bin/cron/*-smoke.php` per lifecycle cron, `bin/heartbeat-smoke.php`,
`tests/StorageTest.php` (backend contract parity),
`bin/storage-smoke.php` in the product repo. Walkthrough:
[code/06](code/06-email-deliverability.md),
[code/08](code/08-uploads-storage-images.md), and
[docs/07-operations.md](docs/07-operations.md).

---

## A note on what's missing

This document is structured around nine architectural decisions,
but real architecture isn't really decomposable into a list. The
decisions interact. The unmodified-controllers bet (2) only works
because schema-per-tenant (1) makes tenancy invisible. The state
machines (6) are what make the webhook contract (4) and the cert
gate (7) safe to drive from crons. The cert gate is only meaningful
because domain verification is a state a machine enforces. The
boring-operations posture (9) is affordable *because* the database
does the hard coordination work everywhere else.

If you read this whole document and the linked walkthroughs, what
you should come away with is not "here are nine clever things" but
"here is a coherent way of turning a single-tenant codebase into a
multi-tenant SaaS without rewriting it, where each piece is shaped
by the others."

Honesty about what's not here: this repo publishes the
architecture, not the product. The controllers, the platform-admin
UI, the marketing site, the runbooks' operational details, and the
tests themselves live in the product repo — every "Verified by"
above names the specific test or smoke there, and every walkthrough
in [code/](code/) cites `file:line` into it, so the claims are
checkable against source even though the source isn't republished
here. Where a decision was resolved differently than designed, the
docs say so (the [docs/08-invariants.md](docs/08-invariants.md)
open-questions list tracks the live ones).

---

[Back to README](README.md)
