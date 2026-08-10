# 🪡 Embroidery Unit

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `embroidery_unit` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `textile`, `tailoring`, `embroidery`, `gst`, `hrms`, `assets` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::factorySections()` — on the [factory floor](_base-factory.md) |

A job-work business: the material belongs to someone else, the unit sells machine time, and the ledger that matters is the one with the customer who sent the lot.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Lots in, lots out, lots overdue
- Machine head-hours run today
- Vendor / customer ledger balances
- Rejection and re-run rate

## 2. 🪡 Industry menu group — **Embroidery**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-star`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Vendor Master | `/embroidery-vendors` | `embroidery` (Enterprise) | `embroidery-vendors.view` |
| Pending Dispatch | `/embroidery-works/pending` | `embroidery` (Enterprise) | `embroidery-works.view` |
| Received | `/embroidery-works/received` | `embroidery` (Enterprise) | `embroidery-works.view` |
| Payment | `/embroidery-payments` | `embroidery` (Enterprise) | `embroidery-payments.view` |
| Vendor Ledger | `/embroidery-vendors/ledger` | `embroidery` (Enterprise) | `embroidery-vendors.view` |
| Job Work | `/job-works` | `manufacturing` (Professional) | `job-works.view` |
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |

- Pending dispatch leads received: the question asked all day is "what is still out".

## 3. 🪡 Embroidery Unit-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Vendor / party master — ✅ `/embroidery-vendors` · `embroidery`
- Pending dispatch — ✅ `/embroidery-works/pending` · `embroidery`
- Received — ✅ `/embroidery-works/received` · `embroidery`
- Payment — ✅ `/embroidery-payments` · `embroidery`
- Party ledger — ✅ `/embroidery-vendors/ledger` · `embroidery`
- Job work — ✅ `/job-works` · `manufacturing`
- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Design library (DST files, stitch count) — ⬜ declared `planned`
- Thread & needle consumption — ⬜

## 4. Changes to the base

- Factory base; the sales spine is job-work billing rather than product sales.

## 5. 📈 Reports this trade needs

Base reports plus:

- Embroidery — ✅ `/reports/embroidery` · `embroidery`
- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Machine-hour costing — ⬜

## 6. 🤖 AI features

- Raw-material purchase suggestion — ✅ `/ai-insights` · `ai` — against open orders
- Low stock prediction — ✅ `/ai-insights` · `ai`
- Dead raw stock — ✅ `/ai-insights` · `ai`
- Machine downtime pattern — ⬜ utilisation is a report, not a prediction
- Scrap-rate outlier detection — ⬜

## 7. 📱 On the counter & on the phone

- Shop-floor terminals print job cards and QC slips (`devices`).
- Biometric attendance feeds piece-rate and shift payroll (`hrms`).

Mobile app menu for this trade: Dashboard · Production orders · QC entry · Stock · Job work · Attendance

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `manufacturing`, `textile`, `tailoring`, `embroidery`, `gst`, `hrms`, `assets`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — purchase, inventory, accounts — a factory can keep books but cannot run production.
- **Business** — GST, HR & payroll, logistics, assets. Still no shop floor.
- **Professional** — the working tier — `manufacturing` (BOM, orders, machines, QC, scrap, costing) and `textile`. Or Business + **Manufacturing Pack**.
- **Enterprise** — adds `tailoring` and `embroidery`, or buy all four with the **Textile & Garment Pack** at a lower tier.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `design_library`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

