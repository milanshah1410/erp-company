# 🍽️ Restaurant / Fine Dine

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `restaurant` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `hrms`, `restaurant`, `loyalty` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The floor is the screen the shift runs on; the kitchen board is its own display on its own device. Settling a bill depletes ingredients through the recipe — idempotently, and allowed to go negative, because a kitchen does not stop service to fix a stock count.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Tables occupied / free, and covers turned today
- Open KOTs and the oldest ticket age
- Today's sales split: dine-in / parcel / delivery
- Ingredient shortfall against tonight's menu
- Waiter-wise sales

## 2. 🍽️ Industry menu group — **Restaurant**

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
| Tables & Sections | `/restaurant/tables` | `restaurant` (Business) | `restaurant-tables.edit` |

- Floor leads, then the running orders, then the kitchen display — that is the order a service happens in.
- Menu leads Recipes: a shop marks dishes sold out every service and writes a recipe once.

## 3. 🍽️ Restaurant / Fine Dine-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Floor plan — ✅ `/restaurant/floor` · `restaurant` — tables, sections, occupancy
  - Tables & sections — ✅ `/restaurant/tables` · `restaurant`
- Running orders / KOT — ✅ `/restaurant/orders` · `restaurant`
- Kitchen display (KDS) — ✅ `/restaurant/kitchen` · `restaurant` — its own device
- Menu card — ✅ `/restaurant/menu` · `restaurant` — menu attributes ride on products
- Recipes — ✅ `/restaurant/recipes` · `restaurant` — settling a bill depletes ingredients
- Combos — ✅ `/restaurant/combos` · `restaurant`
- Happy hours — ✅ `/restaurant/happy-hours` · `restaurant` — offers write onto the document before pricing
- QR menu — public, read-only by design
- Parcel & delivery — POS order types
- Waiter / steward — ✅ `/employees` · `hrms` — performance report below
- Token queue — ✅ `/restaurant/tokens` · `restaurant` — QSR-style counter service
- Table reservation diary — ⬜ not built
- Online food aggregator (Swiggy / Zomato) — ⬜ no connector

## 4. Changes to the base

- Sales: POS remains, but the floor is the real till — most bills start on a table, not at the counter.
- HR is not optional: waiter performance and shift rosters are part of running a service.

## 5. 📈 Reports this trade needs

Base reports plus:

- Waiter performance — ✅ `/reports/waiter-performance` · `restaurant`
- Sales — ✅ `/reports/sales` · `sales` — filter by order type for dine-in / parcel / delivery
- Profitability — ✅ `/reports/profitability` · `sales` — recipe cost against menu price
- Item-wise / hour-wise sales — ⬜
- Wastage register — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- KOT / bill thermal printers per station, and a kitchen display device (`devices`).
- QR menu is a public page; no login, no ordering.

Mobile app menu for this trade: Floor · Orders / KOT · Kitchen · Menu availability · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `hrms`, `restaurant`, `loyalty`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory only — no tables, no KOT.
- **Business** — the working tier: `restaurant` carries floor, KOT, KDS, menu, combos, happy hours and tokens. Or Starter + **Restaurant Pack**.
- **Professional** — adds `manufacturing`, which is how a kitchen gets food costing over recipes at scale, plus channels.
- **Enterprise** — multi-outlet, franchise, white-label.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

