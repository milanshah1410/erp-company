# 💉 Pharma Distribution

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `pharma_distributor` |
| Vertical | Healthcare |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `field_sales`, `broker`, `logistics`, `gst`, `pharmacy`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Wholesale with a clock on every carton. Expiry return to the manufacturer is a routine part of the business, not an exception, and the beat visits chemists rather than kirana shops.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Near-expiry stock returnable this month
- Outstanding by chemist, days past due
- Beat coverage and collections
- Cold-chain items in stock

## 2. 💉 Industry menu group — **Pharma Distribution**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-truck`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Drug Register | `/drug-register` | `pharmacy` (Business) | `drug-register.view` |
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |
| Collections | `/salesman-collections` | `field_sales` (Business) | `salesman-collections.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Outstanding & Ageing | `/reports/receivables-ageing` | `distribution` (Business)<br>plan feature `reports` | `reports.view` |

- Batch and expiry lead everything: the trade's working capital is measured in months of remaining shelf life.

## 3. 💉 Pharma Distribution-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Batch & expiry — ✅ `/batches` · `inventory`
- Expiry return to manufacturer — ✅ `/stock-returns` · `inventory`
- Drug register — ✅ `/drug-register` · `pharmacy`
- Credit control — ✅ `/credit-control` · `distribution`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Salesman & beats — ✅ `/beats` · `field_sales`
  - Collections — ✅ `/salesman-collections` · `field_sales`
- Delivery routes — ✅ `/delivery-routes` · `logistics`
- Prescriptions (institutional supply) — ✅ `/prescriptions` · `pharmacy`
- E-way bill — ✅ `/e-way-bills` · `gst`
- Scheme / bonus quantity — ⬜ declared `planned`
- Cold-chain temperature log — ⬜

## 4. Changes to the base

- POS dropped; Credit Control and Batch are the two promoted groups.

## 5. 📈 Reports this trade needs

Base reports plus:

- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Sales — ✅ `/reports/sales` · `sales`
- Salesman reports — ✅ `/salesman-reports` · `field_sales`
- Expiry-return recovery — ⬜

## 6. 🤖 AI features

- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Low stock prediction — ✅ `/ai-insights` · `ai`
- Expiry-risk stock — ✅ `/ai-insights` · `ai` — reads as dead stock

## 7. 📱 On the counter & on the phone

- Barcode at the godown; the salesman app for orders and collections.

Mobile app menu for this trade: Orders · Outstanding · Collections · Stock / expiry · Beat plan

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `field_sales`, `broker`, `logistics`, `gst`, `pharmacy`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — not workable.
- **Business** — the working tier (`pharmacy`, `field_sales`, `logistics`, `broker`).
- **Professional** — channels.
- **Enterprise** — multi-depot.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `scheme`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

