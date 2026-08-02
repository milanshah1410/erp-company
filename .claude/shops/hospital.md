# 🏥 Hospital / Nursing Home

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `hospital` |
| Vertical | Healthcare |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `gst`, `hrms`, `pharmacy`, `diagnostics`, `hospital`, `projects`, `assets` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

This is the *store*, not the hospital: what the wards ask for, what they consume and what it cost. A ward indent bypasses the sales spine entirely — nothing is being sold, it is being issued.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Open ward indents by department
- Department consumption this month against budget
- Expiry across the store
- Purchase pending against indents

## 2. 🏥 Industry menu group — **Hospital Store**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-building`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Ward Indents | `/hospital-indents` | `hospital` (Business) | `hospital-indents.view` |
| Department Consumption | `/hospital-consumption` | `hospital` (Business) | `hospital-indents.view` |
| Prescriptions | `/prescriptions` | `pharmacy` (Business) | `prescriptions.view` |
| Lab Orders | `/lab-orders` | `diagnostics` (Business) | `lab-orders.view` |
| Test Master | `/lab-tests` | `diagnostics` (Business) | `lab-tests.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Drug Register | `/drug-register` | `pharmacy` (Business) | `drug-register.view` |
| Assets | `/assets` | `assets` (Starter) | `assets.view` |

- Consumption is a menu row of its own rather than a tab inside indents: it is what the administrator opens, and they never open an indent.

## 3. 🏥 Hospital / Nursing Home-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Ward indents — ✅ `/hospital-indents` · `hospital` — issue to a department, not a sale
- Department consumption — ✅ `/hospital-consumption` · `hospital`
- Pharmacy
  - Prescriptions — ✅ `/prescriptions` · `pharmacy`
  - Drug register — ✅ `/drug-register` · `pharmacy`
- Diagnostics
  - Lab orders — ✅ `/lab-orders` · `diagnostics`
  - Test master — ✅ `/lab-tests` · `diagnostics`
- Batch & expiry — ✅ `/batches` · `inventory`
- Biomedical assets — ✅ `/assets` · `assets`
  - Maintenance — ✅ `/asset-maintenances` · `assets`
- Staff — ✅ `/employees` · `hrms`
- Ward return (unused issue back to store) — ⬜ declared `planned`
- IPD / OPD billing — ⬜ declared `planned`
- Patient registration & bed management — ⬜ out of scope for a store module

## 4. Changes to the base

- No POS; the store issues rather than sells.
- Assets and HR are both promoted — a hospital store sits inside an institution.

## 5. 📈 Reports this trade needs

Base reports plus:

- Department consumption — ✅ `/hospital-consumption` · `hospital`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Payables — ✅ `/reports/payables` · `purchase`
- Indent fulfilment rate — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai` — against consumption trend
- Consumption anomaly by department — ⬜

## 7. 📱 On the counter & on the phone

- Barcode at the store window (`devices`).

Mobile app menu for this trade: Indents · Consumption · Stock · Expiry

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `gst`, `hrms`, `pharmacy`, `diagnostics`, `hospital`, `projects`, `assets`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — not workable.
- **Business** — the working tier (`hospital`, `pharmacy`, `diagnostics`, `hrms`, `assets`, `projects`), or a lower tier + **Hospital Store Pack**.
- **Professional** — not needed.
- **Enterprise** — multi-unit hospital groups.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `ward_return`
- `ipd`
- `opd`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

