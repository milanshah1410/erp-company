# 🚚 FMCG Distributor

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `fmcg_distribution` |
| Vertical | Distribution & Wholesale |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `field_sales`, `distribution`, `logistics`, `gst`, `workflow`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Wholesale run at speed: the same credit discipline, but the value is in the beat — hundreds of small outlets visited on a cycle, each with an order, a collection and a scheme to explain.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Beat coverage today: planned / visited / productive
- Outstanding by days past due
- Collections against target
- Van / dispatch load for tomorrow
- Near-expiry stock at distributor and retailer

## 2. 🚚 Industry menu group — **FMCG Distribution**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-truck`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| B2B Orders | `/b2b-orders` | `sales` (Starter) | `b2b-orders.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |
| Daily Visit Plans | `/salesman-visits` | `field_sales` (Business) | `salesman-visits.view` |
| Collections | `/salesman-collections` | `field_sales` (Business) | `salesman-collections.view` |
| Targets & Incentives | `/salesman-targets` | `field_sales` (Business) | `salesman-targets.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Dispatch Planning | `/dispatch-plans` | `logistics` (Business) | `dispatch-plans.view` |
| Outstanding & Ageing | `/reports/receivables-ageing` | `distribution` (Business)<br>plan feature `reports` | `reports.view` |

- Beats lead the salesmen master: the daily question is coverage, not who is on the payroll.

## 3. 🚚 FMCG Distributor-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Beat plans & routes — ✅ `/beats` · `field_sales`
- Daily visit plans — ✅ `/salesman-visits` · `field_sales`
- Live tracking — ✅ `/salesman-tracking` · `field_sales`
- Collections — ✅ `/salesman-collections` · `field_sales`
- Targets & incentives — ✅ `/salesman-targets` · `field_sales`
- Salesman expenses — ✅ `/salesman-expenses` · `field_sales`
- Credit control — ✅ `/credit-control` · `distribution`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- B2B orders — ✅ `/b2b-orders` · `sales`
- Dispatch planning — ✅ `/dispatch-plans` · `logistics`
- Delivery routes — ✅ `/delivery-routes` · `logistics`
- Proof of delivery — ✅ `/proof-of-delivery` · `logistics`
- Barcode & batch — ✅ `/batches` · `inventory`
- Scheme / slab management — ⬜ declared `planned`
- Van sales (sell from the vehicle) — ⬜ declared `planned`
- Secondary sales capture from retailers — ⬜

## 4. Changes to the base

- POS dropped; Field Sales is the operational core and belongs directly under Sales.

## 5. 📈 Reports this trade needs

Base reports plus:

- Salesman reports — ✅ `/salesman-reports` · `field_sales`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Sales — ✅ `/reports/sales` · `sales`
- Beat productivity — ⬜
- Scheme cost vs. uplift — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Collection-risk scoring by party — ⬜ ageing is a report, not a score
- Route / beat productivity — ⬜

## 7. 📱 On the counter & on the phone

- The salesman app is the primary client for this trade, not the desktop.
- Barcode scanning at the godown (`devices`).

Mobile app menu for this trade: Beat plan · Order booking · Collections · Outstanding · Visit check-in

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `field_sales`, `distribution`, `logistics`, `gst`, `workflow`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — not workable — no beats, no credit control.
- **Business** — the working tier (`distribution`, `field_sales`, `logistics`, `workflow`).
- **Professional** — channels.
- **Enterprise** — multi-depot, API.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `scheme`
- `van_sales`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

