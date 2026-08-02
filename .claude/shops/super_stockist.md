# 🏗️ C&F Agent / Super Stockist

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `super_stockist` |
| Vertical | Distribution & Wholesale |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `field_sales`, `distribution`, `logistics`, `gst`, `workflow`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

One tier up from the distributor: stock belongs to the principal, movement is between godowns as often as it is to a customer, and the report the principal wants is secondary sales.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Godown-wise stock and transfers in transit
- Distributor-wise outstanding
- Inward gate entries pending
- Dispatch plan for tomorrow
- Claim / damage stock awaiting credit

## 2. 🏗️ Industry menu group — **C&F / Stockist**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-truck`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| B2B Orders | `/b2b-orders` | `sales` (Starter) | `b2b-orders.view` |
| Warehouses | `/warehouses` | — always on | `warehouses.view` |
| Stock Transfers | `/stock-transfers` | `inventory` (Starter) | `stock-transfers.view` |
| Gate Entries | `/gate-entries` | `inventory` (Starter) | `gate-entries.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |
| Collections | `/salesman-collections` | `field_sales` (Business) | `salesman-collections.view` |
| Dispatch Planning | `/dispatch-plans` | `logistics` (Business) | `dispatch-plans.view` |
| Outstanding & Ageing | `/reports/receivables-ageing` | `distribution` (Business)<br>plan feature `reports` | `reports.view` |

- Multi-warehouse and stock transfer belong in the daily group here — a C&F agent moves more stock between godowns than it sells over a counter.

## 3. 🏗️ C&F Agent / Super Stockist-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Multi-warehouse — ✅ `/warehouses`
- Stock transfers — ✅ `/stock-transfers` · `inventory`
- Gate entries (inward) — ✅ `/gate-entries` · `inventory`
- Credit control — ✅ `/credit-control` · `distribution`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- B2B orders — ✅ `/b2b-orders` · `sales`
- Salesman & beats — ✅ `/beats` · `field_sales`
- Collections — ✅ `/salesman-collections` · `field_sales`
- Dispatch planning — ✅ `/dispatch-plans` · `logistics`
- E-way bill — ✅ `/e-way-bills` · `gst`
- Batch & expiry — ✅ `/batches` · `inventory`
- Scheme management — ⬜ declared `planned`
- Secondary sales reporting to the principal — ⬜ declared `planned`
- Principal-wise stock ownership — ⬜ stock is the tenant's; consignment is not modelled

## 4. Changes to the base

- POS dropped; Inventory is promoted above Sales — this trade is a warehouse business.

## 5. 📈 Reports this trade needs

Base reports plus:

- Stock overview — ✅ `/reports/stock-overview` · `inventory`
- Stock movement — ✅ `/reports/stock-movement` · `inventory`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Salesman reports — ✅ `/salesman-reports` · `field_sales`
- Secondary sales — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Collection-risk scoring by party — ⬜ ageing is a report, not a score
- Route / beat productivity — ⬜

## 7. 📱 On the counter & on the phone

- Barcode at the godown gate (`devices`).
- E-way bills on every outward movement.

Mobile app menu for this trade: Stock · Transfers · Dispatch · Collections · Outstanding

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `field_sales`, `distribution`, `logistics`, `gst`, `workflow`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — not workable.
- **Business** — the working tier (`distribution`, `field_sales`, `logistics`, `workflow`).
- **Professional** — channels.
- **Enterprise** — multi-depot, API, principal integrations.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `scheme`
- `secondary_sales`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

