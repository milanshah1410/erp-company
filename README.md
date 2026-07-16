# Stock & Inventory Management System (SIMS)

Large-scale, multi-warehouse Stock & Inventory Management System.
This repository currently implements the **Phase 1 MVP foundation** from the SRS
(`.claude/Stock_Inventory_Management_SRS_Merged.md`): authentication, RBAC, and the
Master Data module, on top of a data model ready for the later inventory/purchase/sales phases.

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

## Multi-Tenant SaaS layer (stancl/tenancy)

A **database-per-tenant** SaaS layer sits on top of the inventory system
(`stancl/tenancy` v3). Each tenant (a signed-up company) gets an isolated
MySQL database; the shared **central** database holds tenants, domains,
subscription plans, subscriptions, invoices, platform users, and onboarding
progress.

### How requests are routed

| Host | Context | Serves |
|---|---|---|
| `localhost` / `yourapp.com` | **central** | Signup, email verification, provisioning status, plan catalogue, Razorpay webhook |
| `{tenant}.localhost` / `{tenant}.yourapp.com` | **tenant** | The full inventory app (login + all business APIs), resolved to that tenant's DB |

Central routes live in `routes/api.php`; tenant routes in `routes/tenant.php`
(behind `InitializeTenancyBySubdomain` + `PreventAccessFromCentralDomains`).
The `TenancyServiceProvider` gives the tenancy middleware top priority so the
DB connection is switched **before** auth/route-model-binding run.

### Signup → provisioning flow

1. `POST /api/register` (central) — company, admin, email, password, phone,
   chosen subdomain (validated: 3-30 chars, lowercase alnum+hyphen, not
   reserved, not taken). Emails a 6-digit code.
2. `POST /api/verify-email` — verifies the code, dispatches the queued
   `ProvisionTenant` job, and returns `status: provisioning`.
3. `ProvisionTenant` (queued) — creates the tenant + subdomain, creates &
   migrates its database, seeds roles/units/warehouse/company, creates the
   owner admin from the signup credentials, starts a 14-day **trialing**
   subscription, and emails "your account is ready".
4. The signup UI polls `GET /api/provisioning-status/{id}` and redirects to
   `{tenant}.<domain>` when `active`.

### Plans & limits

Three seeded plans (`PlanSeeder`): **Starter ₹999** (1 warehouse / 3 users /
500 products), **Growth ₹2,999** (5 / 15 / 10,000 + barcode + reports),
**Enterprise** (custom, unlimited, API + integrations). Create actions for
products/warehouses/users pass through `plan.limit` middleware
(`EnforcePlanLimit`), which returns HTTP 402 + an upgrade prompt when a limit
is hit. `tenant.active` middleware makes a lapsed (suspended) tenant read-only.

### Local testing (Windows / WAMP)

`*.localhost` resolves to `127.0.0.1` automatically, so no hosts-file edits are
needed.

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

- Sign up at `http://localhost:5173`, grab the dev verification code (shown
  in the response / mail log), and after provisioning open
  `http://<subdomain>.localhost:5173` to log in as the owner.
- Emails use `MAIL_MAILER=log` locally — the verification code is also
  returned in the API response as `dev_code` in the `local` environment.
- A ready-made demo tenant may already exist from setup testing: log in at
  `http://demo1.localhost:5173` with `dee@demo1.test` / `secret1234`.

### Create a tenant from the CLI

```bash
php artisan tinker
>>> $t = App\Models\Tenant::create(['company_name' => 'Acme', 'status' => 'trial']);
>>> $t->domains()->create(['domain' => 'acme']);   // -> acme.localhost
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

Database-per-tenant on subdomains requires:

- **Wildcard DNS** — an `A`/`CNAME` record for `*.yourapp.com` pointing at the
  app server, so every `{tenant}.yourapp.com` resolves without per-tenant DNS.
- **Wildcard TLS certificate** — `*.yourapp.com` via Let's Encrypt
  (DNS-01 challenge, e.g. certbot with a DNS plugin) or Cloudflare, terminated
  at your web server / load balancer. A single-host cert will **not** cover
  tenant subdomains.
- **DB privileges** — the MySQL user needs `CREATE DATABASE` (and `DROP`) so
  `stancl/tenancy` can provision/tear down tenant databases.
- **Custom domains (future)** — tenants can later map their own domain via a
  `CNAME` to `yourapp.com`; add it to the tenant's `domains` records.

## Not yet built (later SRS phases)

Marketplace/GST/accounting integrations, Elasticsearch, WebSockets, and the
Flutter scanning app. Redis/Horizon are wired for production but the dev box
runs the database queue driver.
See the SRS roadmap (section 9) for phasing.
