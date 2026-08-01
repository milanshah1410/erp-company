<picture>
  <source media="(prefers-color-scheme: dark)" srcset="frontend/public/logo-kosha-inverse.svg">
  <img src="frontend/public/logo-kosha.svg" alt="Kosha" height="56">
</picture>

# Kosha — ERP for every trade

Multi-tenant SaaS ERP for Indian SMBs. A shared core (inventory, purchase, sales,
accounting, GST, POS, HR) wrapped in per-trade industry modules — pharmacy, jewellery,
optical, restaurant, agriculture & livestock, import/export, services and projects —
switched on per tenant by shop profile.

The name is कोश: a storehouse, and the layer wrapped around a core. Both are the
architecture. The original SRS this grew out of is
`.claude/Stock_Inventory_Management_SRS_Merged.md`.

## Stack (as built)

| Layer | Technology | SRS asked | Note |
|---|---|---|---|
| Backend | **Laravel 13** | Laravel 12 | `create-project` pulled the current release; 13 is a superset |
| Auth | Laravel Sanctum (bearer tokens) | ✅ | |
| RBAC | spatie/laravel-permission | ✅ | 8 roles, 76 granular permissions |
| Audit / Excel / PDF | spatie/activitylog, maatwebsite/excel, laravel-dompdf | ✅ | installed, wired in later phases |
| DB | **MySQL 9** (`sims`) | MySQL 8 | WAMP ships MySQL 9; InnoDB forced in `config/database.php` |
| Cache/Queue/Session | database driver | Redis | dev fallback — Redis not installed on this box |
| Frontend | Vue 3 + TypeScript + Vite 7 | ✅ | |
| UI | **PrimeVue 4** (Aura theme) | Vuetify 3 *or* PrimeVue | |
| State / Router / HTTP | Pinia, Vue Router 4, Axios | ✅ | |
| Alerts | SweetAlert2 | ✅ | |

## Prerequisites

- PHP 8.3+, Composer 2
- Node 20.19+ / 22.12+
- MySQL running (WAMP) with a database named `sims`

## Backend — run

```bash
cd backend
# .env is preconfigured for MySQL (DB_DATABASE=sims, root, no password)
php artisan migrate --seed # if starting fresh
php artisan migrate:fresh --seed # dropping with starting fresh  
php artisan serve              # http://127.0.0.1:8000
```

**Default login:** `admin@sims.test` / `password` (super-admin)

> On Windows, `php artisan serve` (PHP's built-in server) can log
> "Malformed HTTP request" under rapid keep-alive requests. The Vue dev
> server and normal browser use are unaffected. For heavier local testing,
> serve the app through WAMP/Apache instead.

## Frontend — run

```bash
cd frontend
npm install        # already done
npm run dev        # http://localhost:5173  (proxies /api -> :8000)
```

Log in with the default admin. The sidebar exposes Dashboard + all Master Data
screens; navigation and action buttons are gated by the logged-in user's permissions.

## What's implemented

**Backend**
- Sanctum token auth: `POST /api/login`, `GET /api/me`, `POST /api/logout`
- RBAC: roles (super-admin, admin, warehouse-manager, purchase-manager,
  sales-manager, accountant, staff, auditor) + `<module>.<action>` permissions,
  enforced per route via `permission:` middleware
- Master Data CRUD (list w/ search, filter, sort, pagination; create; show;
  update; delete; validation): **products, categories, brands, units,
  warehouses, suppliers, customers, price-lists**
- Product management (SRS §4.1.1 complete): image upload (public disk +
  `storage:link`), product variants (nested CRUD), batch / expiry / serial
  tracking flags enforced at goods receipt
- Category management (SRS §4.1.2 complete): parent/child hierarchy with
  circular-parent guard, `/categories-tree`, typed attribute definitions
  (text/number/select) with ancestor inheritance, product attribute values
- Warehouse management (SRS §4.1.3 complete): four warehouse types plus
  sub-location tracking (`warehouse_locations`: zone > rack > shelf > bin with
  nesting-order + circular guards) and put-away — GRN lines take an optional
  location, per-location quantities tracked in `stock_locations`
- Supplier management (SRS §4.1.4 complete): profile/GST/credit limit/payment
  terms plus purchase history (`/suppliers/{id}/purchase-history` with summary:
  lifetime value, open exposure, last order) and a PO-aware delete guard
- Customer management (SRS §4.1.5 complete): profile/credit limits plus live
  outstanding balance (opening + unpaid invoices) and sales history with
  summary; SO-aware delete guard
- Sales module core (SRS §4.4 partial): SO draft → confirm (credit-limit +
  stock checks) → deliver (atomic stock-out via StockService + auto invoice) →
  payments (cash/card/upi/credit, partial/paid rollup, overpay guard)
- Purchase module: PO with line items + tax totals, submit → approve →
  cancel workflow, GRN with partial receipt, over-receipt guard, and
  batch/expiry/serial capture (`batches`, `serial_numbers` tables)
- Stock Adjustment (SRS §4.2 "Stock Adjustment" complete): reason-coded
  corrections (physical mismatch, damage, theft, correction, opening stock,
  sample issue, consumption) with a draft → approve/cancel workflow; approve
  posts every line to the immutable ledger atomically via `StockService`
  (positive quantity inward, negative outward), blocks below-zero balances
  unless explicitly allowed, and only draft records can be edited/deleted
- Stock Transfer (SRS §4.2 "Stock Transfer" complete): warehouse A → B with
  draft → dispatch (stock-out at source) → receive (stock-in at
  destination, partial receipt supported) → cancel, so quantity in transit
  is out of source stock but not yet in destination stock
- Cycle Count (SRS §4.2 "Cycle Counting" complete): physical count sheet
  per warehouse, complete posts variance (counted vs system quantity) to
  the ledger as adjustment lines
- Inventory core: `StockService` posts every movement atomically (row-locked)
  to the immutable `stock_ledgers` + `warehouse_stocks` with weighted-average
  costing; read-only Stock and Stock Ledger APIs with low-stock filter
- Stock Inward (SRS §4.2) complete: purchase entry (GRN), opening stock and
  transfer inward were already covered by Goods Receipt / Stock Adjustments /
  Stock Transfers; **Returns Inward** added as its own draft → approve
  document (`stock_returns` + items, optional customer link) posting
  `return_in` to the ledger on approval
- System administration: user management (CRUD + role assignment) and
  role/permission management API (permission matrix grouped by module)
- Full core schema migrated: the above + `product_variants`, `warehouse_stocks`,
  `purchase_orders/items`, `goods_receipts/items`, `batches`, `serial_numbers`

**Frontend**
- Login, auth store with persisted token + permission checks
- App shell (collapsible sidebar, top bar, logout), route guards
- Dashboard with live counts
- Config-driven generic CRUD screen (`src/config/resources.ts` → `CrudView.vue`)
  covering master-data resources with modal forms, server-side pagination,
  inline validation, and SweetAlert2 delete confirmation
- Dedicated pages: **Products** (image upload, tracking flags, variants
  manager), **Purchase Orders** (line-item builder, approve, GRN receive with
  batch/expiry/serial inputs for tracked products), **Current Stock**
  (valuation + low-stock filter), **Stock Ledger** (movement history),
  **Stock Adjustments**, **Stock Transfers**, **Stock Returns** (draft →
  approve, customer-linked), **Users** (role assignment), **Roles &
  Permissions** (per-module matrix)

## Multi-Tenant SaaS layer

A **shared-database, row-scoped** SaaS layer sits on top of the inventory
system: every business table carries a `tenant_id` and is filtered by the
`BelongsToTenant` global scope. Landlord-owned tables — tenants, domains,
plans, subscriptions, invoices, platform users, pending registrations and
onboarding progress — are unscoped and pinned to the `central` connection.

### How requests are routed

Everything is served from a **single host**. The tenant is resolved from the
authenticated user (`App\Support\TenantContext`), not from the URL, so there is
no subdomain-routing middleware in the request path.

| Surface | Serves |
|---|---|
| `/api/*` (public block) | Signup, workspace-address check, email verification, provisioning status, plan catalogue, payment webhooks |
| `/api/*` (auth block) | The full ERP for the signed-in user's tenant |
| `/api/central/*` | The platform (SaaS-owner) panel, on the `central` guard |

**Workspace addresses.** A signup still claims `{subdomain}.{root domain}`:
it is validated and reserved (`App\Support\Subdomain`), stored on
`tenants.subdomain`, and mirrored into a primary `domains` row. Today it is
*identity* — shown at signup, in the ready email and on the platform tenant
list. Pointing wildcard DNS at the app and switching resolution over to the
host is an infra step (see **Production infra** below); the reservation is what
makes that switch possible without re-issuing anyone's address.

Configure the host with `SAAS_ROOT_DOMAIN` (default `yourapp.com`).

### Signup → provisioning flow

1. `GET /api/check-subdomain?company=Acme+Traders` — suggests a free workspace
   address; `?subdomain=acme` checks one live (3-30 chars, lowercase
   alphanumeric + hyphen, not reserved, not taken) and offers alternatives.
2. `POST /api/register` (central) — package, modules, company, workspace
   address, admin, email, password, phone. Creates a `pending_registrations`
   row and emails a 6-digit code. No tenant exists yet.
3. `POST /api/verify-email` — verifies the code (or the magic-link token),
   dispatches the queued `ProvisionTenant` job, returns `status: provisioning`.
4. `ProvisionTenant` (queued) — runs the eight onboarding phases below and
   emails "your account is ready". The address is re-checked at this point and
   gains a numeric tail if it was claimed while the signup sat unverified.
5. The signup UI polls `GET /api/provisioning-status/{id}` and redirects to the
   login page when `active`.

### Onboarding automation

`ProvisionTenant` is organised as the eight documented phases, each run through
`App\Support\ProvisionSteps`, which writes its outcome back to
`pending_registrations.provision_steps` **while the job is still running**:

| # | Phase | What it does |
|---|---|---|
| 1 | Create Tenant | `tenants` row + its primary `domains` row |
| 2 | Select Industry | records the vertical/trade that drives phases 6-7 |
| 3 | Select Plan | 14-day trialing `subscriptions` row + onboarding tracker |
| 4 | Migration | tenants share one schema, so this verifies the shared catalogues (global roles/permissions + the menu template) — idempotent, so it self-heals an unseeded deploy |
| 5 | Seeder | `TenantDatabaseSeeder`: company, branch, warehouse, chart of accounts |
| 6 | Enable Modules | clones the menu catalogue for the tenant, writes `enabled_modules` (+ `module_grants` for anything comped beyond the plan), industry, tax, number series, company profile |
| 7 | Create Admin | the owner account + any extra team members from the wizard |
| 8 | Demo Data | optional sample data (skipped, with a reason, when not requested) |

Both status endpoints (`GET /api/provisioning-status/{id}` and
`GET /api/central/tenants/provisioning/{id}`) return that list — key, label,
`pending|running|done|skipped|failed`, a one-line note of what happened and a
timestamp. The signup screen and the platform panel render it directly, so a
failed provision names the phase it died in instead of only saying "failed".
A registration row with nothing stored (or one predating a phase) is served the
full checklist as `pending`.

Operators can drive the same pipeline from **/platform/tenants → New Tenant**,
optionally hand-picking the module set (step 6) and the demo-data volume
(step 8) instead of taking the trade's preset.

### White label

**/platform/tenants → White Label** (permissions `white-label.view` /
`white-label.edit`) re-skins one workspace across four surfaces:

- **Logo** — uploaded to the `public` disk under `branding/tenant-{id}/`, because
  it has to render on the sign-in page with nobody authenticated. It is stored as
  the tenant's `company_logo_url`, i.e. deliberately the *same* image its
  invoices print — one workspace, one mark.
- **Domain** — extra `domains` rows for customer-owned hosts. Recording a host is
  identity, not routing (same as the workspace subdomain): DNS + certificate are
  infra steps, and what it buys today is that an unauthenticated page served
  there wears the tenant's brand.
- **Theme** — display name, tagline, primary/accent colour (hex only), open-in-
  dark-mode, and whether the "powered by" credit shows.
- **SMTP** — the account this tenant's outgoing mail leaves from, with a
  send-test button. Branded screens sending from the platform's address would
  give the game away, which is why mail lives here.

Everything is stored in the tenant's own `settings` rows, so the panel and the
tenant's Settings → Email screen read one source of truth. `App\Support\Branding`
resolves it back out — by host, falling back field-by-field to the platform brand
in `config('saas.branding')` (`SAAS_BRAND_*` in `.env`), so a workspace that was
never white labelled is untouched.

The SPA reads the public `GET /api/branding` once on boot
(`frontend/src/composables/useBranding.ts`), pushes the colours into
`--safety-amber` / `--ink-blue` and PrimeVue's `--p-primary-*` tokens, and swaps
the logo through `components/BrandLogo.vue` — used by the app top bar, the auth
screens and the B2B portal login.

Because everything is served from **one host**, that host lookup can only answer
with the platform brand for a signed-in user, so the auth store also calls the
tenant-side `GET /api/my-branding` on login / `fetchUser` and re-applies the
authoritative answer. The host lookup is what brands the screens that come
*before* a session (and what will simply start working once wildcard DNS or a
custom domain is pointed at the app). The platform panel is never white
labelled: it resolves to the platform host and so to the platform brand.

### Plans & limits

Five seeded plans (`PlanSeeder`): **Starter ₹999** (1 warehouse / 3 users /
500 products), **Business ₹2,999** (5 / 15 / 10,000 + barcode + reports),
**Professional ₹5,999** (15 / 50 / 50,000 + API + integrations + e-commerce and
light production), **Enterprise ₹11,999** (unlimited, the whole module
catalogue) and **Custom** (quote-based). Each tier is a superset of the one
below, so an upgrade can never switch a module off. The retired `growth` slug
still resolves — `Plan::LEGACY_SLUGS` forwards it to Business, and a migration
repointed its subscribers.

Create actions for products/warehouses/users pass through `plan.limit`
middleware (`EnforcePlanLimit`), which returns HTTP 402 + an upgrade prompt when
a limit is hit. The cap is the plan's, unless the platform panel recorded a
per-tenant override in `tenants.limit_overrides` — see `App\Support\TenantLimits`,
which owns the merge and the usage counts behind /platform/tenants → Limits.
Only creation is metered, so tightening a cap never makes existing records
unreachable. `tenant.active` middleware makes a lapsed (suspended) tenant
read-only.

### Industry templates

The verticals a signup can choose from live in `industry_templates` (seeded by
`IndustryTemplateSeeder`, edited at **/platform/industries**): Retail,
Restaurant & Food, Manufacturing, Healthcare, Logistics & Distribution,
Construction, Agriculture, Services & Projects and Other. Each names the trades
offered under it (`App\Support\ShopProfiles` keys) and the modules a workspace in
it starts with; `GET /api/industries` is what the signup wizard groups its trade
picker by, and `tenants.industry` / `tenants.business_type` are what the platform
panel filters and reports on.

A template is packaging, not code — editing one changes what the next signup is
offered and never reconfigures a workspace already sold. Code-declared industry
*plugins* (`app/Modules/Industry`, e.g. Food & Beverage) are unchanged: each gets
a system template row pointing back at it via `plugin_key`, and the plugin
remains the source of truth for that vertical's trades.

### Agriculture (industry plugin)

`app/Modules/Industry/Agriculture` covers six trades and splits deliberately in
two, because a fertiliser counter and a dairy farm share almost nothing but the
books:

- **Dealers** (`agriculture`, `seeds`, `fertilizer`, `pesticides`) run the
  `agriculture` module: the **input-licence register** (/agri-licences) whose
  point is the expiry date — trading a day past a seed, fertiliser or insecticide
  licence is an offence — plus crop, variety, technical name, nutrient grade and
  germination on the product master, since that is what a farmer asks for.
  Licence *expiry* is derived on every read, never stored, and the register pairs
  each licence type with the stocked products that depend on it, so "no valid
  licence" is only raised where there is stock behind it.
- **Farms** (`dairy_farm`, `poultry`) run the `livestock` module: the herd
  register per head (/livestock/animals), flock batches per placement
  (/livestock/flocks) and the **daily production book** (/livestock/production).

A farm's output is *created*, not purchased, so `FarmProductionService` is the
mirror of the F&B plugin's `RecipeConsumptionService`: saving an entry posts
produce inward (`farm_produce`) and feed outward (`feed_consume`), and moves the
flock's mortality/feed/egg counters. `posted_at` is the idempotency guard — the
double-tap in a shed with one bar of signal must not deplete a sack of feed twice
— and correcting an entry unposts and re-posts inside one transaction. Every
movement allows a negative balance in **both** directions on purpose: the feed was
physically eaten, and a shed that learns the system rejects its entries stops
making them.

### Local testing (Windows / WAMP)

Everything runs on one host, so no hosts-file edits or wildcard DNS are needed
for local development.

```bash
# backend
cd backend
php artisan migrate --seed            # central tables + plans
php artisan serve                     # 127.0.0.1:8000
php artisan queue:work                # REQUIRED — runs the provisioning job

# frontend (restart after pulling the vite.config change)
cd frontend
npm run dev                           # http://localhost:5173  (central signup)
```
ngrok http 5173

- Sign up at `http://localhost:5173/register`, grab the dev verification code
  (pre-filled in the OTP boxes locally, and shown in the mail log), then log in
  as the owner once provisioning finishes.
- Emails use `MAIL_MAILER=log` locally — the verification code is also
  returned in the API response as `dev_code` in the `local` environment.

### Create a tenant from the CLI

```bash
php artisan tinker
>>> $t = App\Models\Tenant::create([
...   'company_name' => 'Acme', 'subdomain' => 'acme', 'status' => 'trial',
... ]);
>>> $t->domains()->create([
...   'domain' => App\Support\Subdomain::fqdn('acme'), 'is_primary' => true,
... ]);
```

### Scheduling & queues

- `subscriptions:process` (scheduled daily 06:00) sends trial-ending reminders
  (3-day, 1-day) and suspends tenants past the grace period. Run the scheduler
  in production via a single cron entry:
  `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
- Provisioning/billing jobs run on the **central** queue. In production use
  Redis + `php artisan horizon`; the database queue works for dev.

### Billing (Razorpay)

Recurring billing is implemented against the Razorpay Subscriptions API
(`App\Services\RazorpayService`, `RazorpayWebhookController`,
`SubscriptionController@changePlan`). Configure in `.env`:

```
RAZORPAY_KEY=rzp_live_xxx
RAZORPAY_SECRET=xxx
RAZORPAY_WEBHOOK_SECRET=xxx        # used to verify webhook signatures
```

Point a Razorpay webhook at `https://yourapp.com/api/razorpay/webhook` for the
`subscription.activated|charged|pending|halted|cancelled|completed` and
`payment.failed` events. When keys are absent (local/dev), plan changes apply
immediately without calling Razorpay.

### Production infra: wildcard DNS + SSL  *(DevOps, outside the Laravel app)*

The app reserves a workspace address per tenant but serves every request from
one host, so **none of this is required to run the product**. It is what turns
`acme.yourapp.com` from a recorded address into a working one:

- **Wildcard DNS** — an `A`/`CNAME` record for `*.yourapp.com` pointing at the
  app server, so every `{tenant}.yourapp.com` resolves without per-tenant DNS.
- **Wildcard TLS certificate** — `*.yourapp.com` via Let's Encrypt
  (DNS-01 challenge, e.g. certbot with a DNS plugin) or Cloudflare, terminated
  at your web server / load balancer. A single-host cert will **not** cover
  tenant subdomains.
- **Set `SAAS_ROOT_DOMAIN`** to the real host before the first production
  signup — addresses already handed out are not re-issued if it changes later.
- **App-side switch (not built)** — resolving the tenant from the host instead
  of the signed-in user means middleware that looks the subdomain up in
  `domains` and pushes it into `TenantContext` before auth runs. The data it
  needs is already there; the middleware is not.
- **Custom domains (future)** — tenants map their own domain via a `CNAME` to
  `yourapp.com`; add it as a non-primary row in the tenant's `domains` records.

## Not yet built (later SRS phases)

Marketplace/GST/accounting integrations, Elasticsearch, WebSockets, and the
Flutter scanning app. Redis/Horizon are wired for production but the dev box
runs the database queue driver.
See the SRS roadmap (section 9) for phasing.
