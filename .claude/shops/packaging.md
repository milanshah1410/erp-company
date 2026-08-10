# 📦 Packaging / Corrugation

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `packaging` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `logistics`, `gst`, `hrms`, `assets`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::factorySections()` — on the [factory floor](_base-factory.md) |

Corrugation and printing in one line. Reels in, printed and creased boxes out — and the two costs that decide the quote are reel wastage and setup waste.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Orders by delivery date
- Reel stock and wastage percentage
- Setup waste by job
- Machine utilisation (corrugator, printer, punch)
- Rejections at customer end

## 2. 📦 Industry menu group — **Packaging**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-box`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Colour Printing | `/colour-printings` | `manufacturing` (Professional) | `colour-printing.view` |
| Machines | `/machines` | `manufacturing` (Professional) | `machines.view` |
| Machine Utilisation | `/machines/utilisation` | `manufacturing` (Professional) | `machines.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |

- Colour Printing sits inside a packaging line as a stage, not as a separate business.

## 3. 📦 Packaging / Corrugation-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Bills of material — ✅ `/boms` · `manufacturing` — reels, ink, gum
- Colour printing — ✅ `/colour-printings` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
  - Utilisation — ✅ `/machines/utilisation` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing` — bursting strength, GSM
- Scrap & setup waste — ✅ `/production-scraps` · `manufacturing`
- Reel planning / trim optimisation — ⬜ declared `planned`
- Die & punch master — ⬜ declared `planned`
- Box specification (ply, flute, size) — ⬜ custom fields today

## 4. Changes to the base

- Factory base; Sales keeps Quotations — packaging is quoted per specification.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Printing — ✅ `/reports/printing` · `manufacturing`
- Reel wastage — ⬜

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

- `reel_planning`
- `die_master`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

