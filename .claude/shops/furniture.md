# 🪑 Furniture Manufacturing

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `furniture` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `logistics`, `gst`, `hrms`, `service`, `projects`, `assets` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Made to order, delivered to a site and installed there — which is why this is the one manufacturing trade whose preset carries `projects` and `service` alongside the shop floor.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Orders by delivery week and site
- WIP by stage: cutting, assembly, polish
- Installations scheduled this week
- Board and hardware shortfall
- Outwork (polish, glass) pending

## 2. 🪑 Industry menu group — **Furniture Unit**

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
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Proof of Delivery | `/proof-of-delivery` | `logistics` (Business) | `dispatch-pods.view` |

- Installation is a `service` job card, and site work is a `project` — neither is a manufacturing concept, which is why both are in this trade's group.

## 3. 🪑 Furniture Manufacturing-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Bills of material — ✅ `/boms` · `manufacturing`
- Job work (polish, glass, upholstery) — ✅ `/job-works` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Scrap & offcut — ✅ `/production-scraps` · `manufacturing`
- Site projects — ✅ `/projects` · `projects`
  - Timesheets — ✅ `/project-timesheets` · `projects`
- Installation job cards — ✅ `/service-jobs` · `service`
- Delivery routes — ✅ `/delivery-routes` · `logistics`
- Proof of delivery — ✅ `/proof-of-delivery` · `logistics`
- Cutting list / board optimisation — ⬜ declared `planned`
- Customer design approval / drawing attachment — ⬜

## 4. Changes to the base

- Factory base plus Projects and Service — the site half of the trade.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Profitability — ✅ `/reports/profitability` · `sales` — per order, including installation
- Site / project cost — ⬜ project budgets exist; a combined works report does not

## 6. 🤖 AI features

- Raw-material purchase suggestion — ✅ `/ai-insights` · `ai` — against open orders
- Low stock prediction — ✅ `/ai-insights` · `ai`
- Dead raw stock — ✅ `/ai-insights` · `ai`
- Machine downtime pattern — ⬜ utilisation is a report, not a prediction
- Scrap-rate outlier detection — ⬜

## 7. 📱 On the counter & on the phone

- Shop-floor terminals print job cards and QC slips (`devices`).
- Biometric attendance feeds piece-rate and shift payroll (`hrms`).

Mobile app menu for this trade: Production orders · Installations · Delivery / POD · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `manufacturing`, `logistics`, `gst`, `hrms`, `service`, `projects`, `assets`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — purchase, inventory, accounts — a factory can keep books but cannot run production.
- **Business** — GST, HR & payroll, logistics, assets. Still no shop floor.
- **Professional** — the working tier — `manufacturing` (BOM, orders, machines, QC, scrap, costing) and `textile`. Or Business + **Manufacturing Pack**.
- **Enterprise** — adds `tailoring` and `embroidery`, or buy all four with the **Textile & Garment Pack** at a lower tier.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `cutting_list`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

