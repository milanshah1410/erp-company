# 🥤 Juice / Beverage Bar

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `juice` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `restaurant` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The smallest F&B trade in the catalogue: a menu, a recipe per glass and a till. Deliberately thin — a juice bar that is handed a floor plan and a kitchen display will use neither.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Sales by hour — a juice bar is a two-peak business
- Fruit and milk shortfall for the day
- Best-selling glass

## 2. 🥤 Industry menu group — **Juice Bar**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-shop`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Menu | `/restaurant/menu` | `restaurant` (Business) | `restaurant-menu.view` |
| Recipes | `/restaurant/recipes` | `restaurant` (Business) | `restaurant-recipes.view` |
| Orders | `/restaurant/orders` | `restaurant` (Business) | `restaurant-orders.view` |

- Three rows and no more. The plugin declares no table, KOT or KDS feature for this trade.

## 3. 🥤 Juice / Beverage Bar-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Menu — ✅ `/restaurant/menu` · `restaurant` — mark items sold out when the fruit runs out
- Recipes — ✅ `/restaurant/recipes` · `restaurant` — per glass; depletes fruit, milk, sugar
- Orders — ✅ `/restaurant/orders` · `restaurant`
- Counter POS — ✅ `/pos` · `pos`
- QR menu — public, read-only
- Seasonal menu switching — ⬜ done by marking items unavailable

## 4. Changes to the base

- No tables, no KOT, no kitchen display, no loyalty — the preset does not carry `loyalty`.
- Purchase and Inventory shrink to a daily fruit list.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales` — glass cost vs. price
- Hour-wise sales — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- One thermal printer; often none at all.

Mobile app menu for this trade: POS · Menu availability · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `restaurant`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory — enough to run the shop, without a menu card.
- **Business** — the working tier (`restaurant` for menu + recipes), or Starter + **Restaurant Pack**.
- **Professional** — not needed.
- **Enterprise** — not needed.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

