# 🏭 Manufacturing / Factory (general)

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `manufacturing` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `textile`, `tailoring`, `embroidery`, `gst`, `hrms`, `assets` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::factorySections()` — on the [factory floor](_base-factory.md) |

The general factory — the trade every other Manufacturing preset specialises. If a workshop does not recognise itself in one of the ten named trades, this is the one it signs up as.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Open manufacturing orders and work in progress
- Today's production against plan
- Machine downtime and utilisation
- QC rejection rate
- Scrap value this month
- Raw-material shortfall against open orders

## 2. 🏭 Industry menu group — **Production**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-cog`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Job Work | `/job-works` | `manufacturing` (Professional) | `job-works.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |
| Machine Utilisation | `/machines/utilisation` | `manufacturing` (Professional) | `machines.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |

- Production is what a planner opens once a day; Shop Floor is what a supervisor lives in during a shift. Two groups, deliberately.

## 3. 🏭 Manufacturing / Factory (general)-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Bills of material — ✅ `/boms` · `manufacturing` — multi-level
- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing` — issue, produce, close
- Job work (outsourced) — ✅ `/job-works` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
  - Utilisation & downtime — ✅ `/machines/utilisation` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Scrap & rejection — ✅ `/production-scraps` · `manufacturing` — material scrap and finished scrap post differently
- Finished-goods costing — ✅ `/reports/production-cost` · `manufacturing`
- Raw material store — ✅ `/stock` · `inventory`
- Gate entry — ✅ `/gate-entries` · `inventory`
- Plant & machinery register — ✅ `/assets` · `assets`
  - Maintenance schedule — ✅ `/asset-maintenances` · `assets`
- Shift / work-centre capacity planning — ⬜ orders are not scheduled against capacity

## 4. Changes to the base

- Uses the [factory base](_base-factory.md): no POS, no loyalty.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Stock movement — ✅ `/reports/stock-movement` · `inventory`
- Vendor performance — ✅ `/reports/vendor-performance` · `purchase`
- Capacity vs. load — ⬜

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

---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

