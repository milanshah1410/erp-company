# 💍 Jewellery

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `jewellery` |
| Vertical | Retail |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `jewellery`, `loyalty` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

The only trade whose price is not on the product. Rate × weight + making − old gold, built at the moment of sale and snapshotted onto the sales line, because tomorrow's rate must not rewrite yesterday's bill.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Today's metal rate, by purity — the first thing looked at, before anything can be quoted
- Old gold taken in today (weight and value)
- Stock by weight, not by piece count
- Counter-wise cash-up (a silver counter reconciles apart from gold)

## 2. 💍 Industry menu group — **Jewellery**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-star-fill`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Metal Rates | `/metal-rates` | `jewellery` (Business) | `metal-rates.view` |
| Old Gold | `/old-gold` | `jewellery` (Business) | `old-gold.view` |

- The rate card is the first screen of the day — nothing in the shop can be priced until it is quoted, so it leads the group.

## 3. 💍 Jewellery-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Metal rates — ✅ `/metal-rates` · `jewellery` — per purity, per day; the bill snapshots the rate it used
- Old gold exchange — ✅ `/old-gold` · `jewellery` — settles as a tender against the new sale
- Purity / karat, gross & net weight — product attributes, priced at sale time
- Making charges — per gram or flat, snapshotted onto `sales_items`
- Hallmark / HUID — product attribute
- Stone details — a register that rolls up into one number on the bill
- Counters & cash-up — ✅ `/pos-counters` · `pos` — gold and silver reconcile separately
- Barcode / RFID tag — ✅ `/label-printing`
- Purchase order — ✅ `/purchase-orders` · `purchase`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Diamond details (4C grading) — ⬜ declared `planned` by the plugin
- Gold savings scheme / chit — ⬜ not built
- Karigar (goldsmith) issue & return — ⬜ job work exists but is not weight-based

## 4. Changes to the base

- Inventory reads by weight as well as by count.
- Counters (`/pos-counters`, `/pos-counters/sessions`) belong in the daily menu — a jeweller with a separate silver counter reconciles per desk.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Stock valuation — ✅ `/reports/valuation` · `inventory` — moves with the rate
- Old gold register — ⬜
- Karigar-wise loss / wastage — ⬜

## 6. 🤖 AI features

- Dead stock recommendation — ✅ `/ai-insights` · `ai` — design-wise ageing matters more here than anywhere
- Low stock prediction — ✅ `/ai-insights` · `ai`
- Rate-trend advisory — ⬜ rates are entered, not forecast

## 7. 📱 On the counter & on the phone

- RFID tag reading and weighing scale (`devices`).
- Rate feed is manual entry today — no live bullion feed.

Mobile app menu for this trade: Dashboard · Metal rates · POS · Old gold · Customers

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `jewellery`, `loyalty`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, accounts — but no `jewellery`, so no rate card. Not a workable tier for this trade.
- **Business** — the working tier: `jewellery` + GST + loyalty. Or Starter + **Jewellery Pack**.
- **Professional** — adds channels and production (relevant only if the shop manufactures).
- **Enterprise** — multi-branch, franchise, white-label.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `diamond_details`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

