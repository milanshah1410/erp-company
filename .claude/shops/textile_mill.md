# 🧶 Textile Mill / Processing

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `textile_mill` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `textile`, `tailoring`, `logistics`, `gst`, `hrms`, `assets`, `devices` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::factorySections()` — on the [factory floor](_base-factory.md) |

Stock here is a roll, not a quantity: it has a length, a width, a shade lot and a shrinkage figure, and two rolls of the same quality are not interchangeable.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Rolls in stock by quality and shade lot
- Inspection: points per 100 m, rolls rejected
- Shrinkage against the last test
- Machine utilisation and downtime
- Orders in hand by delivery week

## 2. 🧶 Industry menu group — **Textile Mill**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-th-large`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Fabric Rolls | `/fabric-rolls` | `textile` (Professional) | `textile.view` |
| Fabric Inspection | `/fabric-inspections` | `textile` (Professional) | `textile.view` |
| Shrinkage Records | `/shrinkage-records` | `textile` (Professional) | `textile.view` |
| Fabric Cutting | `/fabric-cuttings` | `textile` (Professional) | `textile.view` |
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |
| Machine Utilisation | `/machines/utilisation` | `manufacturing` (Professional) | `machines.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |

- `textile` is a Professional module because fabric rolls are stock handling as much as production — a mill needs it even before it needs a BOM.

## 3. 🧶 Textile Mill / Processing-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Fabric rolls — ✅ `/fabric-rolls` · `textile` — length, width, shade lot
- Fabric inspection — ✅ `/fabric-inspections` · `textile` — four-point system
- Shrinkage records — ✅ `/shrinkage-records` · `textile`
- Fabric cutting — ✅ `/fabric-cuttings` · `textile`
- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Bills of material — ✅ `/boms` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
  - Utilisation — ✅ `/machines/utilisation` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Scrap & rejection — ✅ `/production-scraps` · `manufacturing`
- Dyeing / processing lot — ⬜ declared `planned`
- Grey-to-finished conversion tracking — ⬜

## 4. Changes to the base

- Factory base; Inventory reads in metres and rolls.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Stock movement — ✅ `/reports/stock-movement` · `inventory`
- Inspection / rejection by quality — ⬜

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
the trade preset asks for `manufacturing`, `textile`, `tailoring`, `logistics`, `gst`, `hrms`, `assets`, `devices`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — purchase, inventory, accounts — a factory can keep books but cannot run production.
- **Business** — GST, HR & payroll, logistics, assets. Still no shop floor.
- **Professional** — the working tier — `manufacturing` (BOM, orders, machines, QC, scrap, costing) and `textile`. Or Business + **Manufacturing Pack**.
- **Enterprise** — adds `tailoring` and `embroidery`, or buy all four with the **Textile & Garment Pack** at a lower tier.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `dyeing`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

