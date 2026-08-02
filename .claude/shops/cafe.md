# ☕ Cafe / Coffee Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `cafe` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `restaurant`, `loyalty` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A restaurant with a shorter menu, a longer dwell time and a membership card. Same floor and KOT machinery; the difference is that regulars, not covers, are the business.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Covers and average bill by hour — a cafe lives on the 4pm–8pm block
- Member visits today, and points redeemed
- Happy-hour uptake
- Ingredient shortfall for the day's menu

## 2. ☕ Industry menu group — **Cafe**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-shop`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Floor | `/restaurant/floor` | `restaurant` (Business) | `restaurant-tables.view` |
| Orders | `/restaurant/orders` | `restaurant` (Business) | `restaurant-orders.view` |
| Kitchen Display | `/restaurant/kitchen` | `restaurant` (Business) | `kitchen-orders.view` |
| Menu | `/restaurant/menu` | `restaurant` (Business) | `restaurant-menu.view` |
| Recipes | `/restaurant/recipes` | `restaurant` (Business) | `restaurant-recipes.view` |
| Combos | `/restaurant/combos` | `restaurant` (Business) | `menu-combos.view` |
| Happy Hours | `/restaurant/happy-hours` | `restaurant` (Business) | `menu-happy-hours.view` |
| Memberships | `/memberships` | `cosmetics` (Enterprise) | `memberships.view` |
| Membership Plans | `/membership-plans` | `loyalty` (Starter) | `memberships.view` |

- Memberships sit in the trade group rather than under Loyalty: a cafe's club card is worked at the counter every day.

## 3. ☕ Cafe / Coffee Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Floor & tables — ✅ `/restaurant/floor` · `restaurant`
- Orders / KOT — ✅ `/restaurant/orders` · `restaurant`
- Kitchen display — ✅ `/restaurant/kitchen` · `restaurant`
- Menu — ✅ `/restaurant/menu` · `restaurant`
- Recipes — ✅ `/restaurant/recipes` · `restaurant` — espresso, syrup, milk deplete per cup
- Combos — ✅ `/restaurant/combos` · `restaurant`
- Happy hours — ✅ `/restaurant/happy-hours` · `restaurant`
- Membership — ✅ `/memberships` · `cosmetics`
  - Plans — ✅ `/membership-plans` · `loyalty`
- Loyalty points — ✅ `/loyalty` · `loyalty`
- QR menu — public, read-only
- Prepaid wallet / top-up card — ⬜ membership is not a stored-value wallet

## 4. Changes to the base

- Loyalty & Offers is promoted into the trade group as Memberships.
- Purchase is small — a cafe orders from three suppliers; the group can be collapsed.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Waiter performance — ✅ `/reports/waiter-performance` · `restaurant`
- Profitability — ✅ `/reports/profitability` · `sales`
- Hour-wise sales — ⬜
- Member retention / visit frequency — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- KOT and bill printers (`devices`).
- UPI QR at the counter.

Mobile app menu for this trade: Floor · Orders · Menu availability · Members · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `restaurant`, `loyalty`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, loyalty, inventory — a counter with no floor.
- **Business** — the working tier (`restaurant` + `loyalty`), or Starter + **Restaurant Pack**.
- **Professional** — recipes at production scale, channels.
- **Enterprise** — multi-outlet.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

