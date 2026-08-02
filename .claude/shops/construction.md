# 🏗️ Construction / Infrastructure

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `construction` |
| Vertical | — hand-written preset (no plugin) |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `logistics`, `gst`, `hrms`, `projects`, `assets` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Site projects with material issued to them, labour paid on them, plant deployed at them and running bills raised from them. The nearest thing in the catalogue to a factory that moves.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Site-wise budget vs. actual
- Material issued to site this week
- Plant & machinery deployed and idle
- Labour on site today
- Running bills raised and certified

## 2. 🏗️ Industry menu group — **Construction**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-building-columns`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Projects | `/projects` | `projects` (Business) | `projects.view` |
| Timesheets | `/project-timesheets` | `projects` (Business) | `project-timesheets.view` |
| Assets | `/assets` | `assets` (Starter) | `assets.view` |
| Asset Maintenance | `/asset-maintenances` | `assets` (Starter) | `asset-maintenances.view` |
| Job Work | `/job-works` | `manufacturing` (Professional) | `job-works.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Vehicles | `/vehicles` | `logistics` (Business) | `vehicles.view` |

- Material issue to a site is a stock transfer to a site warehouse — the site is a `warehouse`, which is why Inventory stays prominent.

## 3. 🏗️ Construction / Infrastructure-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Site projects — ✅ `/projects` · `projects`
  - Timesheets — ✅ `/project-timesheets` · `projects`
  - Budget vs. actual
- Site as a warehouse — ✅ `/warehouses`
  - Material issue — ✅ `/stock-transfers` · `inventory`
  - Site stock — ✅ `/stock` · `inventory`
- Plant & machinery — ✅ `/assets` · `assets`
  - Maintenance — ✅ `/asset-maintenances` · `assets`
  - Fuel & vehicle expenses — ✅ `/fuel-expenses` · `accounts`
- Subcontractor / job work — ✅ `/job-works` · `manufacturing`
- Labour — ✅ `/employees` · `hrms`
  - Attendance — ✅ `/attendance` · `hrms`
- Transport — ✅ `/vehicles` · `logistics`
  - Delivery routes — ✅ `/delivery-routes` · `logistics`
- Running bill / RA bill — ⬜ raised as an invoice against the project
- Work-order / BOQ register — ⬜
- Retention money & security deposit — ⬜
- Measurement book — ⬜

## 4. Changes to the base

- POS and Loyalty dropped; Projects, Assets and Inventory lead.
- Uses the [factory base](_base-factory.md) shape more than the retail one.

## 5. 📈 Reports this trade needs

Base reports plus:

- Profitability — ✅ `/reports/profitability` · `sales` — per site
- Expenses — ✅ `/reports/expenses` · `accounts`
- Stock movement — ✅ `/reports/stock-movement` · `inventory` — issues to site
- Payables — ✅ `/reports/payables` · `purchase`
- Site-wise material consumption — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Site attendance by biometric or mobile punch (`hrms`).
- Delivery challans and gate passes (`devices`).

Mobile app menu for this trade: Site projects · Material issue · Attendance · Expenses · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `logistics`, `gst`, `hrms`, `projects`, `assets`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — books and stock only.
- **Business** — the working tier (`projects`, `assets`, `hrms`, `logistics`, `gst`).
- **Professional** — if the contractor also fabricates.
- **Enterprise** — multi-company, API.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

