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

### Per-tenant menu: access vs. layout

Two panels on **/platform/tenants** act on the same `menu_items` rows, and both
are gated by the operator's own `can_manage_tenant_access` flag rather than a
platform role — re-parenting a screen is the same power over a workspace as
revoking it.

- **Access** (`TenantAccessController`) answers *may this workspace use this
  screen?* Module switches plus a per-row on/off; switching a row off also strips
  the permissions behind it from the tenant's role matrix and closes the endpoint
  (`App\Support\TenantMenuAccess` documents the three enforcement points).
- **Menu Builder** (`TenantMenuBuilderController`) answers *where does the screen
  sit?* The tenant's tree reordered and re-nested by drag & drop — drop on a row's
  top/bottom edge to place it as a sibling, on its middle to nest it as a child —
  plus renaming, re-iconing, operator-invented groups and a screen picker.
  `PUT /api/central/tenants/{tenant}/menu` takes the whole tree as the desired end
  state: rows are created/updated in the order given and any tenant row absent
  from the payload is deleted, so re-sending it is idempotent.
  `POST …/menu/reset` throws the workspace's custom tree away and re-clones the
  global catalogue.

Both call `TenantMenuAccess::cloneForTenant` first, so a tenant provisioned before
per-tenant menus gets its own copy of the catalogue before its first edit and the
global rows (`tenant_id NULL`) are never touched. Two invariants the tenant-side
`MenuController` depends on are enforced server-side, not just in the UI: a row
with children may not carry a `route` (the nav renders a linked row as a leaf and
would drop the children), and a `route`/`permission` must be one the catalogue
already defines — free text would produce a nav entry the SPA router refuses.
Nesting is capped at 5 levels; the sidebar (`components/NavFlyoutNode.vue`)
cascades all of them, and the Access tree renders from a depth-carrying flat list
so a deeply nested screen stays individually revokable.

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

### Computer & IT (industry plugin)

`app/Modules/Industry/ComputerIt` covers the computer / laptop shop, where the
sale is the *beginning* of the relationship rather than the end of it. It ships
four registers and deliberately no fifth:

- **Installed machines** (/computer-it/assets) — the install base, by serial,
  with where each machine sits and who uses it. Separate from `assets` (the
  company's own fixed assets) and from `serial_numbers` (an inventory device that
  is spent by the stock movement); this record outlives both by years.
- **IT warranties** (/computer-it/warranties) — cover with a **service level**:
  carry-in, on-site, next-business-day on-site. That limb is the one that costs
  money, because it decides whether an engineer travels, and a register that
  stores only dates ends up promising a site visit nobody sold. "Is this covered?"
  is a first-class endpoint that also accepts a bare serial number, because that
  is all the customer on the phone has.
- **Licence keys** (/computer-it/licence-keys) — a key is worth money exactly
  once, so it is unique per tenant, seats are counted rather than rows, OEM keys
  refuse to move to a second machine, and the list shows every key masked. The
  full string comes back only from the single-record endpoint.
- **Service calls** (/computer-it/service-calls) — the desk. A call is not a job
  card: most are fixed on the phone and never become bench work. The response
  promise is derived from priority at logging and then *stored*, so escalating a
  call later cannot rewrite whether the desk was late.

Repair, AMC and barcode are reused, not rebuilt: a job card is a `ServiceJob`
with `it_asset_id` on it (the menu's "Job Cards" row points at /service-jobs),
an AMC is a `ServiceContract` (its "AMC Contracts" row points at
/service-contracts), and labels print from `devices`. Converting a call resolves
cover *again* on the day — manufacturer warranty first, then the machine's AMC —
so a job opens as free work only if something still covers it now.

### Stationery & School Supplies (industry plugin)

`app/Modules/Industry/Stationery` covers the stationery shop, whose year is about
ten weeks long. Between April and June it stops being a retail counter and becomes
a supplier to *institutions* — and an institution does not buy anything. It
publishes a class list, appoints a vendor, and sends four hundred parents to fetch
it one at a time. The plugin ships the two registers that follow from that and
nothing else:

- **Institutions** (/stationery/institutions) — the school as a *catalogue
  owner*, which is not the same record as the customer it bills to: the trust that
  pays and the school that publishes the list are routinely different names, and
  most of these accounts never owe the shop a rupee. It carries the session, the
  board, the roll, and **two** discount rates — one for the school, one for its
  parents — because there are two buyers and only one of them is a person.
  `requires_po` is a flag rather than a lesson learned in September: an order for
  a school that will not settle without quoting its own purchase-order number is
  refused until that number is entered.
- **Class booklists** (/stationery/class-kits) — the list, as a document. Not a
  `Bom`: a BOM is consumed to *produce* something and is driven by a manufacturing
  order, while a kit produces nothing, sells, has optional lines, prices
  differently for the school than for the parent, and routinely has three items
  short in June and still has to sell. Only a **published** list can be supplied
  from, because a half-typed booklist looks exactly like a finished one until
  someone goes home short. Each line keeps the school's own wording verbatim — the
  parent is holding the sheet it was copied from and will compare.

Everything downstream is core, deliberately. **Bulk Sales already existed**:
quantity slabs are `price_lists.min_quantity`, resolved by `Customer::priceFor()`,
so a four-hundred-set school order gets the bulk rate through the same code path
the till uses on a parent — a slab table here would be a second opinion the POS
never asked for. A **school order** is a `SalesOrder` on the new `institution`
channel carrying `stn_institution_id` / `stn_class_kit_id` / `stn_po_number`, so
picking, phased delivery, invoicing and the receivable all already work;
/stationery/school-orders is a projection over those orders, not a document of its
own. A **tender** is a `SalesQuotation`, and `SchoolQuotationObserver` carries the
school onto the order at conversion — as an observer, because that conversion
usually happens on the ordinary Quotations screen. Barcode, purchase, inventory
and GST are the `devices`, `purchase`, `inventory` and `gst` modules, untouched.

/stationery/season-demand rolls every published list up by product at its expected
sets and nets it against stock. That is the number the trade's year turns on:
short in May means turning away the customers who pay for the year, long in July
means a godown of Class 7 books nobody will ever ask for again.

### Books & Periodicals (industry plugin)

`app/Modules/Industry/BookStore` covers the book shop, which looks like ordinary
retail and is not, for one structural reason: **the shop does not name its own
stock**. Every other trade in this ERP invents its SKUs, writes its own
descriptions and prices what it likes. A bookshop receives articles that already
carry a worldwide identity, a price printed on the cover by somebody else, and a
set of people on the front that customers ask for by name. Four registers follow
from that, and nothing else does:

- **Titles** (/books/titles) — not a table. A title *is* a product, so it is
  created, priced, stocked and retired on the Products screen like everything
  else; this screen is the catalogue indexed the way a bookshop searches it (an
  ISBN, a house, a language, somebody on the cover). It carries the two things no
  CRUD form can do: the **ISBN lookup** the counter uses when a customer phones,
  and the credits editor.
- **Authors** (/books/authors) — every credit on a cover, with its role: author,
  co-author, editor, translator, illustrator, compiler. The role is why this is a
  join table and not an `author` column — a translated novel has two names on it
  and the translator's is what sells it, an anthology has an editor and no author
  at all, and a picture book routinely has one person twice. The name is unique
  per tenant because the failure this register guards against is the *duplicate*:
  the same writer entered twice splits their shelf and the missing half is
  invisible.
- **Publishers** (/books/publishers) — a counterparty, not a brand. It sets the
  printed price, sells at a **trade discount** off it (which *is* the shop's
  margin), and often takes unsold copies back for credit. `is_returnable` +
  `return_window_days` are the commercial fact that separates this trade from
  every other one here: on a sale-or-return house, slow stock is a deadline; on a
  firm-sale house it is a loss, and a buyer who cannot tell them apart writes off
  books that could have been credited.
- **Book Languages** (/books/languages) — the shelf plan. The same title in
  Gujarati, Hindi and English is three articles at three prices, and the sections
  are signed in the languages they hold. Named `BookLanguage` because the app
  already has a *locale*, which is a different thing entirely.

The ISBN does the load-bearing work. `Services\Isbn` is pure arithmetic — both
forms carry a check digit, so a mistyped number is caught at the keyboard instead
of three weeks later when the wrong book arrives (one wrong digit is usually a
different title that also exists). `Observers\TitleObserver` normalises it,
derives the other form, and **copies the ISBN-13 onto `products.barcode`**,
because an ISBN-13 beginning 978/979 *is* the EAN-13 printed on the back cover.
That is what makes a title scannable at the core till without this module touching
the POS at all — and it only ever *adopts* a barcode that is empty or still holds
core's SKU default, so a shop that typed its own number keeps it. The Titles
screen flags the drift when they stop agreeing, which is otherwise invisible: the
scanner just stops finding the book and the shop concludes it is out of stock.

Three of the seven things the trade asks for are deliberately somebody else's.
**Category** is core `categories` — a genre is a category, and a parallel register
would file every book twice. **Barcode** is `inventory` + `devices`. **Online
Orders** is the `ecommerce` module's channel inbox, which the trade presets tick —
but reached through the Ecommerce menu group rather than mirrored into this one.
`books` is Starter and `ecommerce` is Professional, and a second menu row makes
its group's module an *owner* of that row's permission (`groupAvailable()` passes
if any owner is enabled), so mirroring it would grant `channel-orders` to every
Starter book shop and land the ones who clicked it on a 403. That shortcut is only
safe between modules in the same tier — which is why the Toy Shop group may use it
for the gift desk and this one may not.

Two trades: `book_store`, and `academic_bookshop`, which is the same register plus
the Stationery vertical's booklists and school orders — a preset, not code.

### Sports & Fitness (industry plugin)

`app/Modules/Industry/Sports` covers the sports shop, which is two businesses in
one unit. Selling kit is ordinary retail and the ERP has served it since the
Retail vertical shipped — brands are core, size and colour runs are the `garment`
variant matrix, cover is the core `warranty` card, a restring is a `ServiceJob`,
and a barcode is a barcode. The plugin ships **none** of that. What it ships is the
other half: the shop **hires its goods out and expects them back**, which nothing
else in this product does.

That one fact breaks the assumption everything else is built on. Everywhere else
an article leaves the shop once and the transaction is over. A hire bike leaves
eighty times and comes back seventy-nine; it earns by the day rather than by the
piece; it is worth less after every trip; and most of the money that crosses the
counter is a **deposit**, which is not the shop's money at all. Two registers
follow, and nothing else:

- **The fleet** (/sports/fleet) — individually identified kit, held *outside*
  inventory on purpose. Hiring "stock" and posting a movement each way would put a
  hundred and twenty entries against one bicycle in a season, swing its valuation
  between zero and cost every weekend, and still not answer the only question the
  shop asks — *is bike 7 free on Sunday?*, which a quantity cannot answer whatever
  is done to it. Each asset carries **four nullable rates** (hour/day/week/month),
  because the same article is genuinely hired on different bases by different
  customers; a null means "not offered that way" and the contract refuses that
  basis rather than billing a plausible zero. **On hire is derived** from the
  contracts, never stored — only "on the bench" and "written off" are stored,
  because they are the two a contract cannot tell you.
- **The hire contract** (/sports/rentals) — booking and hire as one row at
  different points of its life, because splitting them is how a fleet gets
  double-booked. Lines are per asset with **no quantity**: a partial return is the
  normal case (six bikes out, four back Sunday, two on Monday), and each line is
  priced *at its own return* and then frozen, so the four that came back on time
  never pick up Monday's late fee. Rate, deposit and late-fee percentage are
  snapshotted onto the line — repricing the fleet must not reprice a contract that
  is already running.

Availability is where the money is. An **unreturned line occupies its asset
indefinitely** — not until its due time, but until it is physically back — so a
bike that was due Saturday and is still out cannot be promised for Sunday. A
booking occupies its window exactly as a hire does. Overdue is likewise derived
(`out`, past `due_at` plus the grace agreed on the day), never a stored flag
waiting on a sweep: the overdue board is the screen the module is opened for.

Money leaves through the ordinary spine — a `SalesOrder` on the new `rental`
channel plus an `Invoice` — but the **deposit never does**. A security deposit is
the customer's money; running it through sales would inflate turnover by the size
of the float and charge GST on a sum that has to be handed back. So it is carried
on the contract, and only the part the shop *keeps* becomes income, as a tender on
the new `deposit` payment mode. There is deliberately no "forfeited" column:
keeping a deposit is always keeping it *for* something, so a lost bat is a damage
charge with the deposit applied to it — which leaves a bill that says what
happened. Hire, late fee and damage bill as three `is_service` products created on
first use, never as the bicycle, because an invoice line for the bike reads as a
sale of it.

The Sports Shop menu group puts second doors on **Repair Jobs** (/service-jobs)
and **Warranty Cards** (/warranties), which is the supported way to say "two
modules reach this". That shortcut is only safe between modules in the same tier
or lower — `sports` is Business, `service` is Business and `warranty` is Starter,
so every plan that sells the vertical already carries both. A damaged hire asset
goes to that same bench via `service_jobs.sports_asset_id`, one nullable column on
the core job card rather than a second repair register.

One trade: `sports_shop`. Court and turf booking is declared `planned` rather than
implied — hiring *time* is a different question from hiring an object, and a fleet
register cannot honestly pretend to answer it.

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
