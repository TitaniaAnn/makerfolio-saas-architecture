# 04 — Billing: the platform plane (Pro/Studio subscriptions)

**Audience:** developers working on makerfolio-saas.
**Scope:** the platform Stripe account — how a tenant upgrades to a paid plan (Pro/Studio), and how webhook events drive `subscriptions.local_status` + `tenants.plan_id`.

> makerfolio runs two entirely separate Stripe planes. This walkthrough is the **platform** plane: makerfolio's own Stripe account, billing tenants for their subscription tier. The other plane — each tenant's Stripe **Connect** account for their storefront checkout — is [`05-shop-connect-plane.md`](./05-shop-connect-plane.md). Different accounts, secret keys, webhook endpoints, and DB tables. They can both be active in one request without interference (`PlatformStripe.php:6-10`).

## Map — the files
| File | Role |
| --- | --- |
| `includes/PlatformStripe.php` | SDK wrapper for the platform key: `enabled()`, `ensureCustomer`, `createCheckoutSession`, `createPortalSession`, `cancelSubscriptionAtPeriodEnd`, `refundPayment`, `constructWebhookEvent`. |
| `includes/Plan.php` | `public.plans` rows (Free/Pro/Studio); `find`/`findBySlug`/`free`, plan-cap `canCreate`/`decideCanCreate`, `tenantPlanFlag`. |
| `includes/Subscription.php` | Local mirror of a Stripe subscription; `mapStripeStatus` (the `_map_stripe_status` port), `transitionTo`, lookups. |
| `public/platform-webhook/stripe/index.php` | Apex-only webhook receiver; idempotency ledger + the five event handlers. |
| `public/admin/billing/upgrade.php` | Plans page; renders cards, gated by each plan's `is_active` + `availability`. |
| `public/admin/billing/checkout.php` | POST → `createCheckoutSession` → redirect to Stripe. |
| `public/admin/billing/return.php` | Checkout success landing; meta-refresh polls until the webhook flips the plan. |
| `public/admin/billing/manage.php` | Current plan, portal, cancel UI. |
| `public/admin/billing/portal.php` | POST → `createPortalSession` → redirect to Stripe Customer Portal. |
| `public/admin/billing/cancel.php` | POST → `cancelSubscriptionAtPeriodEnd` (Stripe-side flip; webhook mirrors locally). |
| `bin/set-plan-prices.php` | Wire `plans.stripe_price_id` from Stripe Price IDs. |

## The flow
Each billing controller gates on `Auth::requireLogin()` + `require_role(Role::OWNER)` (see [`02-auth-and-security.md`](./02-auth-and-security.md)) — billing is OWNER-only.

1. **Plans page** — `upgrade.php` lists `public.plans WHERE is_active = 1` (`upgrade.php:20`). Each paid card's CTA is disabled when the plan's `availability` isn't `BUYABLE`, `!PlatformStripe::enabled()`, or the plan has no `stripe_price_id` (`upgrade.php:123`).
2. **Checkout** — the card POSTs to `checkout.php`: `csrf_verify()`, refuse if the plan isn't buyable or `!PlatformStripe::enabled()` (`checkout.php:28`), then `PlatformStripe::createCheckoutSession($tenantId, $planId)` (`checkout.php:34`) and redirect to the returned URL.
3. **`createCheckoutSession`** — `PlatformStripe.php:102-135`: resolves the plan, refuses if `stripe_price_id` is empty (`PlatformStripe.php:109`), `ensureCustomer` (idempotent customer create, tenant_id in metadata, `PlatformStripe.php:65-95`), then a `mode=subscription` Checkout Session with `tenant_id`+`plan_id` in both session and `subscription_data.metadata` (`PlatformStripe.php:122-131`) — that metadata is how the webhook resolves the sub back to a tenant.
4. **Stripe Checkout** completes → redirects to `return.php?session_id=…` (`PlatformStripe.php:120`). `return.php` does **not** write the plan — it meta-refreshes every 3s (`return.php:29`) until `currentPlan.slug` is `pro_monthly`/`pro_annual` (`return.php:21`). The flip is the webhook's job.
5. **Webhook flips state** — Stripe POSTs `customer.subscription.created`/`invoice.paid` to `/platform-webhook/stripe/`. `invoice.paid` mirrors `subscriptions.plan_id` onto `tenants.plan_id` (`index.php:274-277`). Once `tenants.plan_id` points at the Pro row, `Plan::canCreate` reads the new (NULL = unlimited) caps and `enforce_plan_cap` stops blocking — Pro "unlocks." There are no feature gates; what changes is brand/URL/capacity (`upgrade.php:63`).

Cancel/manage: `manage.php` renders current plan + Stripe-portal and cancel forms (only when `$sub && PlatformStripe::enabled()`, `manage.php:55`). `cancel.php` calls `cancelSubscriptionAtPeriodEnd` (`cancel.php:24`) — Stripe-side only; the local `cancel_at_period_end` flag arrives via the `customer.subscription.updated` webhook.

## The webhook idempotency contract
`index.php` mirrors the inherited shop-webhook contract (CLAUDE.md invariant 3 / MODELS.md "`subscriptions.local_status` is webhook-driven only", `MODELS.md:628-632`):

1. **Refuse if not configured** — `!PlatformStripe::enabled()` returns **503** (not 200) so Stripe retries later rather than silently dropping events (`index.php:24-27`).
2. **Verify signature** — `constructWebhookEvent` against `STRIPE_PLATFORM_WEBHOOK_SECRET`; failure → 400 (`index.php:43-49`).
3. **Claim the event** — `INSERT INTO public.billing_events (stripe_event_id, …)` **first** (`index.php:61-66`). The PK serializes concurrent retries. On duplicate-key (`23505`/`23000`): if `processed_at IS NOT NULL`, ack **200** and stop (real duplicate); else fall through and re-run (a prior attempt crashed) (`index.php:67-84`).
4. **Dispatch + stamp in one transaction** — `Database::transaction()` switches on `event->type`, then `UPDATE billing_events SET processed_at = now(), handler_result = ?` (`index.php:88-121`). On throw: `processed_at` stays NULL, `handler_result='FAILED'`, return 500 so Stripe retries (`index.php:122-131`).

Which event flips what:
| Stripe event | Handler | Effect |
| --- | --- | --- |
| `customer.subscription.created` / `.updated` | `handle_subscription_upsert` (`index.php:144`) | INSERT or `transitionTo` the `subscriptions` row; `local_status = mapStripeStatus(sub.status)`; resolves `plan_id` from `items[0].price.id`. |
| `invoice.paid` | `handle_invoice_paid` (`index.php:249`) | `transitionTo('ACTIVE')`, roll period_end, **mirror `sub.plan_id` → `tenants.plan_id`**, restore tenant from `GRACE`. |
| `invoice.payment_failed` | `handle_invoice_failed` (`index.php:289`) | Only on `attempt_count >= 4` or `next_payment_attempt === null`: `transitionTo('PAST_DUE')` + tenant `ACTIVE → GRACE`. |
| `customer.subscription.deleted` | `handle_subscription_deleted` (`index.php:220`) | `transitionTo('CANCELLED')` + flip `tenants.plan_id` back to `Plan::free()`; clear `GRACE → ACTIVE`. |
| anything else | default | marked `IGNORED`, processed so Stripe stops retrying (`index.php:109-112`). |

`_map_stripe_status` is `Subscription::mapStripeStatus` (`includes/Subscription.php`) (`Subscription.php:37-47`): `incomplete→PENDING_PAYMENT`, `active`/`trialing→ACTIVE`, `past_due`/`unpaid→PAST_DUE`, `canceled→CANCELLED`, `incomplete_expired→INCOMPLETE_EXPIRED`, **unknown→CANCELLED (fail closed)** so a Stripe rename can't leave a sub silently ACTIVE.

## Availability gating
The old `PAID_PLANS_COMING_SOON` env flag is gone. Launch state lives in the data: each `public.plans` row carries `is_active` plus an `availability` column (`BUYABLE` / `COMING_SOON` / `TEASER`), edited at `/platform-admin/plans/`. `marketing/pricing.php` and `upgrade.php` render pills/CTAs from those columns, and `checkout.php` refuses a hand-crafted POST for any plan that isn't `is_active` + `BUYABLE`, so the gate isn't just cosmetic. Go-live = create live Stripe Prices → `bin/set-plan-prices.php` → flip availability in the admin.

## Invariants & gotchas
- **`local_status` / `plan_id` are webhook-driven, never optimistic** — controllers never write either directly. `cancel.php` flips Stripe only; the local mirror waits for `customer.subscription.updated`. Breaking this re-introduces the drift `_map_stripe_status` exists to prevent (CLAUDE.md invariant 3).
- **State changes go through `transitionTo`** — `Subscription::transitionTo` (`Subscription.php:94`) validates against `STATUSES`, whitelists writeable columns, and writes a `PlatformActivity` audit row. Don't `UPDATE subscriptions` directly (CLAUDE.md invariant 4).
- **`enabled()` is all-or-nothing** — false until secret + publishable + webhook secret are all set (`PlatformStripe.php:19-24`); every method/surface fails shut rather than half-configured.
- **No `stripe_price_id` ⇒ no checkout** — paid plans ship with NULL price; `createCheckoutSession` throws (`PlatformStripe.php:109`) and the CTA is disabled until `bin/set-plan-prices.php` wires them.
- **Pinned API version** — `PINNED_API_VERSION = '2023-10-16'` (`PlatformStripe.php:37`) **must** match the dashboard endpoint version or payload shapes disagree (STRIPE_AUDIT F3/F6).
- **Idempotency keys** — `ensureCustomer` keys on tenant id (`PlatformStripe.php:88`); `refundPayment` takes an optional key (`PlatformStripe.php:186`). Checkout/Portal sessions do **not** (STRIPE_AUDIT F4, accepted LOW).
- **dunning threshold** — `handle_invoice_failed` ignores attempts 1–3; GRACE only on the 4th failure or Stripe giving up (`index.php:300-304`). The 60-day SUSPENSION sweep is a separate cron, not here.

## Tests & verification
- `tests/SubscriptionTest.php` — locks `STATUSES` order and every `mapStripeStatus` mapping incl. case-insensitivity and the fail-closed default.
- `tests/PlanTest.php` — `decideCanCreate` decision matrix (NULL limit = unlimited; `<` for counts, `<=` for storage bytes).
- `bin/billing-webhook-smoke.php` — the receiver contract against live Postgres: new-event row + `processed_at`, duplicate short-circuit, crash→FAILED→retry, the upsert/`invoice.paid` flips. Uses the `whsec_test_skip_verify` escape hatch.
- `bin/billing-flow-smoke.php` — upgrade/downgrade mechanics: plan-cap blocks at Free then clears after upgrade; `findCurrentByTenant` through PENDING→ACTIVE→CANCELLED; `tenants.plan_id` round-trip free→pro→free.
- `bin/refund-flow-smoke.php` — `refundPayment` synthetic `re_test_*` id under `sk_test_stub`; `billing_events_revocations` insert; the app-side "already revoked" guard.
- `bin/studio-plans-smoke.php` — Studio seed rows (tier/`max_admin_users`/flags); `canCreate('admin_users')` flips blocked→allowed onto Studio; `role` CHECK constraint.

## See also
- [`05-shop-connect-plane.md`](./05-shop-connect-plane.md) — the OTHER Stripe plane (tenant Connect storefront).
- [`02-auth-and-security.md`](./02-auth-and-security.md) — `require_role(Role::OWNER)`, CSRF.
- `STRIPE_AUDIT.md`, `MODELS.md` (subscriptions/plans/billing_events).
