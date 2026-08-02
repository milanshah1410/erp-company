# 🧪 Fertiliser Dealer

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `fertilizer` |
| Vertical | Agriculture |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `field_sales`, `logistics`, `gst`, `agriculture`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Sold by weight, bought in truckloads, and every bag is subsidised — so the licence, the scale and the farmer's credit line are the three things the counter touches all day.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Licence validity
- Stock by weight (MT) against the season plan
- Farmer credit outstanding
- Inward truckloads pending gate entry

## 2. 🧪 Industry menu group — **Fertiliser Dealer**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-sun`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Input Licences | `/agri-licences` | `agriculture` (Business) | `agri-licences.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Devices | `/devices` | `devices` (Starter) | `devices.view` |
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |

- The weighing scale is a first-class row: fertiliser is sold by weight and reconciled by weight.

## 3. 🧪 Fertiliser Dealer-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Fertiliser licence — ✅ `/agri-licences` · `agriculture`
- Batch / lot — ✅ `/batches` · `inventory`
- Weighing scale — ✅ `/devices` · `devices`
- Farmer credit — ✅ `/credit-control` · `distribution`
- Delivery to village — ✅ `/delivery-routes` · `logistics`
- Gate entry (inward truck) — ✅ `/gate-entries` · `inventory`
- Counter POS — ✅ `/pos` · `pos`
- Field team & beats — ✅ `/beats` · `field_sales`
- Subsidy POS (mFMS / PoS device) — ⬜ declared `planned`
- FCO label / nutrient declaration — ⬜ declared `planned`

## 4. Changes to the base

- Logistics is promoted — bags move by truck and tractor, not by hand.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Stock overview — ✅ `/reports/stock-overview` · `inventory` — read in MT
- Subsidy reconciliation — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Season demand forecast — ⬜

## 7. 📱 On the counter & on the phone

- Weighing scale is mandatory (`devices`).
- Government subsidy PoS is a separate device and is not integrated.

Mobile app menu for this trade: POS · Stock · Farmer credit · Deliveries

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `field_sales`, `logistics`, `gst`, `agriculture`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory.
- **Business** — the working tier (`agriculture`, `field_sales`, `logistics`).
- **Professional** — not needed.
- **Enterprise** — multi-godown.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `subsidy_pos`
- `fco_label`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

