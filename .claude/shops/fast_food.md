# 🍔 Fast Food / QSR

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `fast_food` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `restaurant`, `loyalty` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

No tables, so the token queue *is* the floor screen. Speed is the product: order, token, kitchen, call, and the whole cycle is meant to fit in five minutes.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Open tokens and the oldest one
- Average order-to-serve time
- Sales split: counter / parcel / delivery
- Combo uptake
- Ingredient shortfall for the shift

## 2. 🍔 Industry menu group — **QSR**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-shop`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Token Queue | `/restaurant/tokens` | `restaurant` (Business) | `kitchen-orders.view` |
| Orders | `/restaurant/orders` | `restaurant` (Business) | `restaurant-orders.view` |
| Kitchen Display | `/restaurant/kitchen` | `restaurant` (Business) | `kitchen-orders.view` |
| Menu | `/restaurant/menu` | `restaurant` (Business) | `restaurant-menu.view` |
| Recipes | `/restaurant/recipes` | `restaurant` (Business) | `restaurant-recipes.view` |
| Combos | `/restaurant/combos` | `restaurant` (Business) | `menu-combos.view` |

- Token Queue leads — a QSR has no tables, so this is its floor screen.

## 3. 🍔 Fast Food / QSR-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Token queue — ✅ `/restaurant/tokens` · `restaurant`
- Orders / KOT — ✅ `/restaurant/orders` · `restaurant`
- Kitchen display — ✅ `/restaurant/kitchen` · `restaurant`
- Menu — ✅ `/restaurant/menu` · `restaurant`
- Recipes — ✅ `/restaurant/recipes` · `restaurant`
- Combos — ✅ `/restaurant/combos` · `restaurant` — the trade's main pricing instrument
- Parcel & delivery — POS order types
- QR menu — public, read-only
- Counter POS — ✅ `/pos` · `pos`
- Customer-facing token display — ⬜ the KDS is kitchen-side only
- Aggregator orders (Swiggy / Zomato) — ⬜ no connector

## 4. Changes to the base

- Tables and the floor plan are dropped entirely.
- Purchase is a short list of daily suppliers.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales`
- Waiter performance — ✅ `/reports/waiter-performance` · `restaurant` — counter staff
- Hour-wise / peak analysis — ⬜
- Service-time report — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- KOT printer + kitchen display (`devices`).
- UPI QR at the counter.

Mobile app menu for this trade: Tokens · Orders · Kitchen · Menu availability · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `restaurant`, `loyalty`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory.
- **Business** — the working tier (`restaurant`), or Starter + **Restaurant Pack**.
- **Professional** — channels, production.
- **Enterprise** — multi-outlet / franchise.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

