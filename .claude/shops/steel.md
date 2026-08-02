# ⚙️ Steel / Fabrication

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `steel` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `logistics`, `gst`, `hrms`, `projects`, `assets`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Bought by weight, sold by drawing. The offcut is real money, the heat number is traceability, and most jobs are site projects rather than shelf products.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Jobs by site and delivery week
- Offcut recovery this month
- Machine and welding-bay load
- Section / plate stock by weight
- Outwork (galvanising, machining) pending

## 2. ⚙️ Industry menu group — **Fabrication**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-cog`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Job Work | `/job-works` | `manufacturing` (Professional) | `job-works.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |
| Projects | `/projects` | `projects` (Business) | `projects.view` |

- Offcut recovery is posted through scrap, and the project is what the job is billed against.

## 3. ⚙️ Steel / Fabrication-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Bills of material — ✅ `/boms` · `manufacturing`
- Job work (galvanising, machining) — ✅ `/job-works` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Scrap & offcut recovery — ✅ `/production-scraps` · `manufacturing`
- Site projects — ✅ `/projects` · `projects`
- Delivery & transport — ✅ `/delivery-routes` · `logistics`
- Weight-based costing (kg rate) — ⬜ declared `planned`
- Heat number / mill certificate traceability — ⬜ declared `planned`

## 4. Changes to the base

- Factory base plus Projects; Logistics matters — sections are heavy and freight is a cost line.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Profitability — ✅ `/reports/profitability` · `sales`
- Weight reconciliation (bought vs. used vs. offcut) — ⬜

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
the trade preset asks for `manufacturing`, `logistics`, `gst`, `hrms`, `projects`, `assets`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — purchase, inventory, accounts — a factory can keep books but cannot run production.
- **Business** — GST, HR & payroll, logistics, assets. Still no shop floor.
- **Professional** — the working tier — `manufacturing` (BOM, orders, machines, QC, scrap, costing) and `textile`. Or Business + **Manufacturing Pack**.
- **Enterprise** — adds `tailoring` and `embroidery`, or buy all four with the **Textile & Garment Pack** at a lower tier.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `heat_number`
- `weight_calculation`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

