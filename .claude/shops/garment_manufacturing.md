# 🧵 Garment / Apparel Unit

*ERP menu requirement. Factory / back-office base + the modules below.*

| | |
|---|---|
| Trade key | `garment_manufacturing` |
| Vertical | Manufacturing |
| Base | [Factory / back-office base](_base-factory.md) |
| Industry modules | `manufacturing`, `textile`, `tailoring`, `embroidery`, `logistics`, `gst`, `hrms`, `assets`, `devices` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Cutting, tailoring and embroidery are three separate chains that a garment order passes through, each with its own vendors, rate cards and pending books. The factory's real question is never "how much stock" but "where is this order right now".

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-factory.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Orders in hand vs. dispatched, by delivery week
- Cut / stitched / embroidered / finished — WIP by stage
- Tailor pending work and overdue lots
- Embroidery lots out with vendors
- Fabric shortfall against cut plan

## 2. 🧵 Industry menu group — **Garment Unit**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-cog`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Fabric Rolls | `/fabric-rolls` | `textile` (Professional) | `textile.view` |
| Fabric Cutting | `/fabric-cuttings` | `textile` (Professional) | `textile.view` |
| Tailor Master | `/tailors` | `tailoring` (Enterprise) | `tailors.view` |
| Pending Work | `/tailor-works/pending` | `tailoring` (Enterprise) | `tailor-works.view` |
| Completed Work | `/tailor-works/completed` | `tailoring` (Enterprise) | `tailor-works.view` |
| Payment | `/tailor-payments` | `tailoring` (Enterprise) | `tailor-payments.view` |
| Vendor Master | `/embroidery-vendors` | `embroidery` (Enterprise) | `embroidery-vendors.view` |
| Pending Dispatch | `/embroidery-works/pending` | `embroidery` (Enterprise) | `embroidery-works.view` |
| Job Work | `/job-works` | `manufacturing` (Professional) | `job-works.view` |
| Quality Inspection | `/quality-inspections` | `manufacturing` (Professional) | `quality-inspections.view` |
| Scrap & Rejection | `/production-scraps` | `manufacturing` (Professional) | `production-scrap.view` |

- The three chains (`textile`, `tailoring`, `embroidery`) are separate modules so a unit that only stitches never sees an embroidery vendor ledger.

## 3. 🧵 Garment / Apparel Unit-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Order & BOM — ✅ `/boms` · `manufacturing`
- Manufacturing orders — ✅ `/manufacturing-orders` · `manufacturing`
- Fabric
  - Fabric rolls — ✅ `/fabric-rolls` · `textile`
  - Cutting — ✅ `/fabric-cuttings` · `textile`
  - Inspection — ✅ `/fabric-inspections` · `textile`
  - Shrinkage — ✅ `/shrinkage-records` · `textile`
- Tailoring
  - Tailor master — ✅ `/tailors` · `tailoring`
  - Contractors — ✅ `/contractors` · `tailoring`
  - Rate card — ✅ `/rate-cards` · `tailoring`
  - Piece rate — ✅ `/piece-rates` · `tailoring`
  - Pending work — ✅ `/tailor-works/pending` · `tailoring`
  - Completed work — ✅ `/tailor-works/completed` · `tailoring`
  - Bonus — ✅ `/tailor-bonuses` · `tailoring`
  - Payment — ✅ `/tailor-payments` · `tailoring`
  - Ledger — ✅ `/tailors/ledger` · `tailoring`
- Embroidery
  - Vendor master — ✅ `/embroidery-vendors` · `embroidery`
  - Pending dispatch — ✅ `/embroidery-works/pending` · `embroidery`
  - Received — ✅ `/embroidery-works/received` · `embroidery`
  - Payment — ✅ `/embroidery-payments` · `embroidery`
  - Vendor ledger — ✅ `/embroidery-vendors/ledger` · `embroidery`
- Job work — ✅ `/job-works` · `manufacturing`
- Quality inspection — ✅ `/quality-inspections` · `manufacturing`
- Scrap & rejection — ✅ `/production-scraps` · `manufacturing`
- Machines — ✅ `/machines` · `manufacturing`
- Size-ratio / assortment planning — ⬜ declared `planned`
- Trim & accessory (button, zip, label) store — ⬜ ordinary stock items today

## 4. Changes to the base

- Factory base, plus all three garment chains — the heaviest menu in the catalogue.

## 5. 📈 Reports this trade needs

Base reports plus:

- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Tailor — ✅ `/reports/tailor` · `tailoring`
- Embroidery — ✅ `/reports/embroidery` · `embroidery`
- Tailor performance — ✅ `/tailor-performance` · `tailoring`
- Order-wise WIP by stage — ⬜

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
the trade preset asks for `manufacturing`, `textile`, `tailoring`, `embroidery`, `logistics`, `gst`, `hrms`, `assets`, `devices`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — purchase, inventory, accounts — a factory can keep books but cannot run production.
- **Business** — GST, HR & payroll, logistics, assets. Still no shop floor.
- **Professional** — the working tier — `manufacturing` (BOM, orders, machines, QC, scrap, costing) and `textile`. Or Business + **Manufacturing Pack**.
- **Enterprise** — adds `tailoring` and `embroidery`, or buy all four with the **Textile & Garment Pack** at a lower tier.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `size_ratio`


---

[← All shops](README.md) · [Factory / back-office base](_base-factory.md)

