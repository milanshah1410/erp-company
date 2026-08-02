# 🥐 Bakery

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `bakery` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `manufacturing`, `gst`, `loyalty`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A shop with a factory behind it. It works to recipes but has no tables, so it runs Manufacturing rather than Restaurant — and the cake order book, with a promised date and time, is the screen the counter argues over.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Cake orders due today and tomorrow, with pickup times
- Production plan vs. what is actually baked
- Ingredient shortfall for today's batch
- Expiry / day-old stock to mark down
- Counter sales by hour

## 2. 🥐 Industry menu group — **Bakery**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-gift`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Cake Orders | `/cake-orders` | `sales` (Starter) | `cake-orders.view` |
| Recipes | `/restaurant/recipes` | `restaurant` (Business) | `restaurant-recipes.view` |
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |

- Cake Orders leads: it is the only screen here with a promise on it.
- Recipes is reached through Manufacturing → Production for this trade, which is why the module set says `manufacturing` and not `restaurant`.

## 3. 🥐 Bakery-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Cake / custom order book — ✅ `/cake-orders` · `sales` — date, time, message, advance
- Recipes — ✅ `/restaurant/recipes` · `restaurant` — baking depletes ingredients
- Production orders — ✅ `/manufacturing-orders` · `manufacturing` — the day's batch
- Bills of material — ✅ `/boms` · `manufacturing`
- Batch & expiry — ✅ `/batches` · `inventory` — day-old markdown
- Weighing scale — ✅ `/devices` · `devices`
- Barcode & price label — ✅ `/label-printing`
- Counter POS — ✅ `/pos` · `pos`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Advance / part payment on an order — taken as a payment against the order
- Photo-cake artwork upload — ⬜
- Baking sheet / oven schedule — ⬜ production orders are not time-slotted

## 4. Changes to the base

- Adds a Production group from the [factory base](_base-factory.md) — this trade straddles both bases.
- Warranty, B2B orders and field sales are not relevant; drop them.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Production cost — ✅ `/reports/production-cost` · `manufacturing` — recipe cost vs. counter price
- Manufacturing — ✅ `/reports/manufacturing` · `manufacturing`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Order-book / fulfilment report — ⬜
- Wastage (unsold day-old) — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- Weighing scale, label printer, thermal printer (`devices`).
- WhatsApp for order-ready messages.

Mobile app menu for this trade: Cake orders · Production plan · POS · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `manufacturing`, `gst`, `loyalty`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — POS, inventory, loyalty, cake orders (they ride on `sales`).
- **Business** — GST, HR, expenses.
- **Professional** — the working tier — `manufacturing` carries recipes, production orders and food costing. Or Business + **Manufacturing Pack**.
- **Enterprise** — multi-outlet with a central kitchen.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `menu`

- Note the plan seam: a bakery that wants food costing must reach Professional, because recipes are behind `manufacturing` for producing trades. Deliberate, but worth knowing when selling.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

