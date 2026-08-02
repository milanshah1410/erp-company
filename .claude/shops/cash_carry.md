# 🏪 Cash & Carry / Wholesale Counter

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `cash_carry` |
| Vertical | Distribution & Wholesale |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `distribution`, `gst`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A wholesale counter: the buyer is a retailer, the price is a slab, and the goods leave with them. Credit still matters, but the till is back — which is why this is the one distribution trade with `pos`.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Counter-wise sales and cash-up
- Outstanding by party
- Slab-price exceptions
- Fast movers by pack size

## 2. 🏪 Industry menu group — **Cash & Carry**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-shopping-cart`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Tills | `/pos-counters` | `pos` (Starter) | `pos-counters.view` |
| Shift Cash-Up | `/pos-counters/sessions` | `pos` (Starter) | `pos-counters.view` |
| Price Lists | `/price-lists` | — always on | `price-lists.view` |
| B2B Orders | `/b2b-orders` | `sales` (Starter) | `b2b-orders.view` |

- Tills and cash-up belong here because a cash & carry runs several counters; credit control stays because a retailer buying on account is still a credit decision.

## 3. 🏪 Cash & Carry / Wholesale Counter-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Counter billing — ✅ `/pos` · `pos`
- Tills — ✅ `/pos-counters` · `pos`
  - Shift cash-up — ✅ `/pos-counters/sessions` · `pos`
- Slab / wholesale price list — ✅ `/price-lists`
- Credit control — ✅ `/credit-control` · `distribution`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- B2B orders — ✅ `/b2b-orders` · `sales`
- Multi-warehouse — ✅ `/warehouses`
- E-way bill — ✅ `/e-way-bills` · `gst`
- Barcode & label — ✅ `/label-printing`
- Membership / trade card — ⬜ `loyalty` carries memberships; the preset ticks loyalty but not membership tiers by trade
- Scheme management — ⬜ declared `planned`

## 4. Changes to the base

- Keeps POS, unlike its sibling distribution trades.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Counter-wise sales — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Collection-risk scoring by party — ⬜ ageing is a report, not a score
- Route / beat productivity — ⬜

## 7. 📱 On the counter & on the phone

- Barcode scanners and thermal printers on every counter (`devices`).

Mobile app menu for this trade: POS · Stock check · Party outstanding · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `distribution`, `gst`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory, no credit control.
- **Business** — the working tier (`distribution` + `pos`).
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `scheme`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

