# 🍨 Ice Cream Parlour

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `ice_cream` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `manufacturing`, `gst`, `loyalty`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A parlour is a freezer with a counter in front of it. What it needs that no other F&B trade does is knowing what is in which freezer, because a flavour is out of stock when the tub is empty, not when the ledger says so.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Freezer-wise flavour availability
- Sales by flavour today
- Batch expiry
- Ingredient / base-mix shortfall

## 2. 🍨 Industry menu group — **Parlour**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-sun`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Freezers & Flavours | `/parlour/freezers` | `inventory` (Starter) | `stock.view` |
| Recipes | `/restaurant/recipes` | `restaurant` (Business) | `restaurant-recipes.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |

- Freezers & Flavours leads: it is the counter's stock screen and reads off `stock.view`.

## 3. 🍨 Ice Cream Parlour-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Freezers & flavours — ✅ `/parlour/freezers` · `inventory` — what is in which cabinet
- Recipes — ✅ `/restaurant/recipes` · `restaurant` — base mix, per litre
- Production orders — ✅ `/manufacturing-orders` · `manufacturing`
- Batch & expiry — ✅ `/batches` · `inventory`
- Counter POS — ✅ `/pos` · `pos`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Cone / cup / topping as add-ons — products on the bill
- Menu card — ⬜ declared `planned`; parlours sell off the freezer, not a card
- Cold-chain temperature log — ⬜

## 4. Changes to the base

- Adds Production from the [factory base](_base-factory.md) if the parlour makes its own.
- Freezers replace the generic stock screen as the counter's first stop.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales` — flavour-wise via product grouping
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Freezer-wise sales — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- Thermal printer; scale for by-weight tubs (`devices`).

Mobile app menu for this trade: Freezers · POS · Production · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `manufacturing`, `gst`, `loyalty`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — POS, inventory, loyalty — freezers ride on `inventory`, so this tier already shows them.
- **Business** — GST, HR.
- **Professional** — own-manufacture (recipes, production orders) via `manufacturing`.
- **Enterprise** — multi-outlet.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `menu`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

