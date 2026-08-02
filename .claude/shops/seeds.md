# 🌱 Seed Dealer / Nursery

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `seeds` |
| Vertical | Agriculture |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `field_sales`, `gst`, `agriculture`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The narrowest agri trade: a seed licence, a lot number and a very short selling window. Germination is what the farmer will come back about, and it is the one thing not yet recorded.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Licence validity
- Lot-wise stock against the sowing window
- Village-wise offtake this season
- Expiry / germination-date board

## 2. 🌱 Industry menu group — **Seed Dealer**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-sun`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Input Licences | `/agri-licences` | `agriculture` (Business) | `agri-licences.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |

- Four rows. The plugin gives this trade a licence register, batch tracking and a field team — nothing else is built for it yet.

## 3. 🌱 Seed Dealer / Nursery-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Seed licence — ✅ `/agri-licences` · `agriculture`
- Lot / batch — ✅ `/batches` · `inventory` — lot number, packing date
- Variety & crop attributes — product master
- Village routes — ✅ `/beats` · `field_sales`
- Counter POS — ✅ `/pos` · `pos`
- Germination test record — ⬜ declared `planned`
- Nursery batch (raising, transplant) — ⬜ declared `planned`
- Truthful-label / certified-seed tagging — ⬜

## 4. Changes to the base

- Very small menu — Purchase and Inventory carry most of the year.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Variety-wise sales — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Season demand forecast — ⬜

## 7. 📱 On the counter & on the phone

- Counter printer; barcode is uncommon on seed packets in this trade.

Mobile app menu for this trade: POS · Stock · Licences · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `field_sales`, `gst`, `agriculture`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory.
- **Business** — the working tier (`agriculture` + `field_sales`), or Starter + **Agri Inputs Pack**.
- **Professional** — not needed.
- **Enterprise** — multi-branch.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `germination_test`
- `nursery_batch`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

