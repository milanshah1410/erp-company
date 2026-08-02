# 🐔 Poultry Farm

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `poultry` |
| Vertical | Agriculture |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `logistics`, `gst`, `hrms`, `livestock`, `assets` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Managed by flock, not by bird. A batch is placed, fed, watched for mortality and sold as one economic unit — and the numbers that decide profit are feed conversion and mortality percentage.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Flock-wise age, count and mortality today
- Egg production or weight gain against standard
- Feed stock and consumption per flock
- Batches ready for sale

## 2. 🐔 Industry menu group — **Poultry**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-heart`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Flock Batches | `/livestock/flocks` | `livestock` (Business) | `livestock-flocks.view` |
| Daily Production | `/livestock/production` | `livestock` (Business) | `farm-production.view` |
| Assets | `/assets` | `assets` (Starter) | `assets.view` |
| Employees | `/employees` | `hrms` (Business) | `employees.view` |
| Attendance | `/attendance` | `hrms` (Business) | `attendance.view` |

- The flock register is the unit of account; individual birds are never tracked.

## 3. 🐔 Poultry Farm-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Flock batches — ✅ `/livestock/flocks` · `livestock` — placement date, count, house
- Daily production — ✅ `/livestock/production` · `livestock` — eggs or weight gain
- Mortality — recorded against the flock
- Feed consumption — against the flock
- Farm assets — ✅ `/assets` · `assets` — sheds, feeders, brooders
- Labour — ✅ `/employees` · `hrms`
  - Attendance — ✅ `/attendance` · `hrms`
- Sale of birds / eggs — ✅ `/sales-orders` · `sales`
- Weight gain / FCR standard curve — ⬜ declared `planned`
- Hatchery management — ⬜ declared `planned`
- Vaccination schedule — ⬜ declared `planned`
- Biosecurity / visitor log — ⬜

## 4. Changes to the base

- No POS in the preset — sales are to traders on order. No loyalty, no CRM pipeline.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Expenses — ✅ `/reports/expenses` · `accounts` — feed
- Stock movement — ✅ `/reports/stock-movement` · `inventory`
- Flock-wise P&L — ⬜
- Mortality / FCR trend — ⬜

## 6. 🤖 AI features

- Feed purchase suggestion — ✅ `/ai-insights` · `ai`
- Low stock prediction — ✅ `/ai-insights` · `ai`
- Mortality anomaly detection — ⬜

## 7. 📱 On the counter & on the phone

- Manual entry from the shed register; no device integration.

Mobile app menu for this trade: Flocks · Daily production · Mortality · Feed · Attendance

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `logistics`, `gst`, `hrms`, `livestock`, `assets`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — inventory and accounts.
- **Business** — the working tier (`livestock`, `hrms`, `assets`, `logistics`).
- **Professional** — processing.
- **Enterprise** — multi-farm, integration contracts.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `weight_gain`
- `hatchery`
- `vaccination`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

