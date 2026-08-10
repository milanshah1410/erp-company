# 🧪 Chemical / Paints

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `chemical` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `logistics`, `gst`, `hrms`, `assets`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::factorySections()` — on the [factory floor](_base-factory.md) |

Everything is a batch and every batch is testable. The formulation is the product, the batch is the unit of traceability, and a failed QC is a recall risk rather than a rework.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Batches in production and awaiting QC
- Batch expiry board
- QC pass / fail by batch
- Raw-material shortfall
- Machine and reactor availability

## 2. 🧪 Industry menu group — **Chemical**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-cog`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |

- Batches are promoted out of Inventory: for this trade the batch, not the product, is the thing being managed.

## 3. 🧪 Chemical / Paints-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing` — one order, one batch
- Bills of material — ✅ `/boms` · `manufacturing`
- Batch & expiry — ✅ `/batches` · `inventory`
- Batch QC — ✅ `/quality-inspections` · `manufacturing`
- Scrap & rejection — ✅ `/production-scraps` · `manufacturing`
- Machines / reactors — ✅ `/machines` · `manufacturing`
- Plant register — ✅ `/assets` · `assets`
- Formulation master (with revisions) — ⬜ declared `planned`; a BOM is not a formula
- MSDS / safety data sheets — ⬜ declared `planned`
- Hazard & storage class — ⬜

## 4. Changes to the base

- Factory base; Batch tracking is mandatory rather than optional.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory` — batch expiry
- Batch genealogy / traceability — ⬜

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
the trade preset asks for `manufacturing`, `logistics`, `gst`, `hrms`, `assets`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — purchase, inventory, accounts — a factory can keep books but cannot run production.
- **Business** — GST, HR & payroll, logistics, assets. Still no shop floor.
- **Professional** — the working tier — `manufacturing` (BOM, orders, machines, QC, scrap, costing) and `textile`. Or Business + **Manufacturing Pack**.
- **Enterprise** — adds `tailoring` and `embroidery`, or buy all four with the **Textile & Garment Pack** at a lower tier.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `formulation`
- `msds`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

