# 🐄 Dairy Farm

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `dairy_farm` |
| Vertical | Agriculture |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `logistics`, `gst`, `hrms`, `livestock`, `assets` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Production *creates* stock here — the mirror image of a restaurant recipe, which consumes it. The animal register is the asset list, the daily yield book is the production line, and feed is the biggest cost in the business.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Today's milk yield, morning and evening
- Animals in milk / dry / sick
- Feed consumed against yield
- Collection dispatched to the dairy
- Vet and breeding events due

## 2. 🐄 Industry menu group — **Farm**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-heart`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Animals | `/livestock/animals` | `livestock` (Business) | `livestock-animals.view` |
| Daily Production | `/livestock/production` | `livestock` (Business) | `farm-production.view` |
| Milk Collection | `/milk-collections` | `purchase` (Starter) | `milk-collections.view` |
| Assets | `/assets` | `assets` (Starter) | `assets.view` |
| Asset Maintenance | `/asset-maintenances` | `assets` (Starter) | `asset-maintenances.view` |
| Employees | `/employees` | `hrms` (Business) | `employees.view` |
| Attendance | `/attendance` | `hrms` (Business) | `attendance.view` |

- Daily Production leads: the registers are set up once, but the book is keyed twice a day and is what actually moves stock.

## 3. 🐄 Dairy Farm-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Daily production — ✅ `/livestock/production` · `livestock` — yield in, creates stock
- Animal register — ✅ `/livestock/animals` · `livestock` — tag, breed, lactation
- Milk collection — ✅ `/milk-collections` · `purchase`
- Feed consumption — recorded against production
- Farm assets — ✅ `/assets` · `assets` — milking machine, chaff cutter, shed
  - Maintenance — ✅ `/asset-maintenances` · `assets`
- Labour — ✅ `/employees` · `hrms`
  - Attendance — ✅ `/attendance` · `hrms`
- Counter / local sale — ✅ `/pos` · `pos`
- Breeding & AI register — ⬜ declared `planned`
- Vaccination schedule — ⬜ declared `planned`
- Fat / SNF quality per collection — ⬜ declared `planned`
- Veterinary treatment history — ⬜

## 4. Changes to the base

- Factory-ish: no loyalty, no CRM pipeline. POS stays for gate sales.
- HR is not optional — farm labour is the second cost after feed.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Stock movement — ✅ `/reports/stock-movement` · `inventory` — production in
- Expenses — ✅ `/reports/expenses` · `accounts` — feed is the line that matters
- Animal-wise yield — ⬜
- Feed conversion ratio — ⬜

## 6. 🤖 AI features

- Low stock (feed) prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Yield anomaly detection — ⬜

## 7. 📱 On the counter & on the phone

- Milk analyser and weighing are manual entry; no device driver.
- Attendance biometric for farm labour (`hrms`).

Mobile app menu for this trade: Daily production · Animals · Feed · Attendance · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `logistics`, `gst`, `hrms`, `livestock`, `assets`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — inventory and accounts only.
- **Business** — the working tier — `livestock`, `hrms`, `logistics`, `assets`. Or Starter + **Livestock & Poultry Pack**.
- **Professional** — processing (`manufacturing`) if the farm packs its own.
- **Enterprise** — multi-farm.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `breeding`
- `vaccination`
- `milk_collection`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

