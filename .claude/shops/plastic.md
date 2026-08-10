# 🧴 Plastic / Moulding

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `plastic` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `logistics`, `gst`, `hrms`, `assets`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::factorySections()` — on the [factory floor](_base-factory.md) |

Machine-hour costing with a twist: the rejects are worth something. Regrind goes back into the hopper, so scrap is a recovery rather than only a loss.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Machine-wise output and cycle count
- Downtime by reason
- Regrind stock and re-use ratio
- Rejection percentage by mould
- Granule stock against open orders

## 2. 🧴 Industry menu group — **Moulding**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-cog`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |
| Machine Utilisation | `/machines/utilisation` | `manufacturing` (Professional) | `machines.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |

- Regrind is why scrap posting matters here: material scrap returns to raw stock rather than being written off.

## 3. 🧴 Plastic / Moulding-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Bills of material — ✅ `/boms` · `manufacturing` — granule, masterbatch
- Machines — ✅ `/machines` · `manufacturing`
  - Utilisation & downtime — ✅ `/machines/utilisation` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Scrap, rejection & regrind — ✅ `/production-scraps` · `manufacturing`
- Plant register — ✅ `/assets` · `assets`
  - Maintenance — ✅ `/asset-maintenances` · `assets`
- Mould master (cavities, life, shots) — ⬜ declared `planned`
- Cycle-time capture per machine — ⬜ declared `planned`

## 4. Changes to the base

- Factory base; Assets is not optional — moulds and machines are the business.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Machine utilisation — ✅ `/machines/utilisation` · `manufacturing`
- Mould-wise rejection — ⬜

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

- `mould_master`
- `cycle_time`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

