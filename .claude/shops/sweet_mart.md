# 🍬 Sweet Mart / Namkeen

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `sweet_mart` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `manufacturing`, `gst`, `loyalty`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A bakery that sells by weight and makes its year in three weeks. Everything is produced in batches, priced per kilogram, and has a shelf life measured in days.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Today's production by item (kg)
- Counter sales by weight, not by count
- Festival order book (Diwali / Raksha Bandhan)
- Expiry / shelf-life board
- Ghee, khoya and dry-fruit shortfall

## 2. 🍬 Industry menu group — **Sweet Mart**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-gift`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Recipes | `/restaurant/recipes` | `restaurant` (Business) | `restaurant-recipes.view` |
| Manufacturing Orders | `/manufacturing-orders` | `manufacturing` (Professional) | `manufacturing-orders.view` |
| Bills of Materials | `/boms` | `manufacturing` (Professional) | `boms.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Devices | `/devices` | `devices` (Starter) | `devices.view` |

- The weighing scale is a first-class row here: nothing leaves the shop unweighed, so `devices` belongs in the daily group.

## 3. 🍬 Sweet Mart / Namkeen-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Recipes — ✅ `/restaurant/recipes` · `restaurant` — per kg yield
- Production orders — ✅ `/manufacturing-orders` · `manufacturing` — today's batch by item
- Bills of material — ✅ `/boms` · `manufacturing`
- Batch & shelf life — ✅ `/batches` · `inventory`
- Weighing scale — ✅ `/devices` · `devices` — price by weight at the counter
- Counter POS — ✅ `/pos` · `pos`
- Barcode / price label — ✅ `/label-printing`
- Festival / bulk order book — ⬜ taken as sales orders today
- Gift box & packing material — ⬜ `gift` module is not in this preset
- Tray / dish return (deposit) — ⬜

## 4. Changes to the base

- Adds Production from the [factory base](_base-factory.md).
- Loyalty is minor; the festival order book matters more.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Production cost — ✅ `/reports/production-cost` · `manufacturing`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Item-wise yield vs. recipe — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- Weighing scale is mandatory; label and thermal printers (`devices`).

Mobile app menu for this trade: Production · POS · Stock · Orders · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `manufacturing`, `gst`, `loyalty`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — POS, inventory, weighing scale.
- **Business** — GST, HR, expenses.
- **Professional** — the working tier — recipes and batch production via `manufacturing`.
- **Enterprise** — multi-outlet with a central karkhana.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

