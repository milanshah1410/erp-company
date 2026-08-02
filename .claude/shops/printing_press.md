# 🖨️ Printing Press

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `printing_press` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `textile`, `logistics`, `gst`, `hrms`, `assets`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Every job is bespoke and most of the cost is incurred before the first good sheet: plates, make-ready and setup waste. A press that cannot see setup waste per job cannot price the next one.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Jobs in press, in binding, in dispatch
- Setup waste by job
- Machine utilisation by press
- Paper stock against open jobs
- Outwork (lamination, binding) pending

## 2. 🖨️ Industry menu group — **Printing**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-print`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Colour Printing | `/colour-printings` | `manufacturing` (Professional) | `colour-printing.view` |
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Job Work | `/job-works` | `manufacturing` (Professional) | `job-works.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |
| Machine Utilisation | `/machines/utilisation` | `manufacturing` (Professional) | `machines.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |

- Colour Printing is the press-side screen; the generic manufacturing order is the job wrapper around it.

## 3. 🖨️ Printing Press-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Colour printing jobs — ✅ `/colour-printings` · `manufacturing`
- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Bills of material — ✅ `/boms` · `manufacturing` — paper, ink, plates
- Job work (lamination, binding) — ✅ `/job-works` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
  - Utilisation — ✅ `/machines/utilisation` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Scrap, setup waste & rejection — ✅ `/production-scraps` · `manufacturing`
- Imposition / plate planning — ⬜ declared `planned`
- Paper reel / ream stock in weight — ⬜ stocked by count today
- Job estimate / quotation calculator — ⬜ quotations are generic

## 4. Changes to the base

- Factory base; Sales keeps Quotations — most work is quoted before it is taken.

## 5. 📈 Reports this trade needs

Base reports plus:

- Printing — ✅ `/reports/printing` · `manufacturing`
- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Job-wise profitability — ⬜

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
the trade preset asks for `manufacturing`, `textile`, `logistics`, `gst`, `hrms`, `assets`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — purchase, inventory, accounts — a factory can keep books but cannot run production.
- **Business** — GST, HR & payroll, logistics, assets. Still no shop floor.
- **Professional** — the working tier — `manufacturing` (BOM, orders, machines, QC, scrap, costing) and `textile`. Or Business + **Manufacturing Pack**.
- **Enterprise** — adds `tailoring` and `embroidery`, or buy all four with the **Textile & Garment Pack** at a lower tier.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `imposition`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

