# 🌾 Agri Centre / Agro Dealer

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `agriculture` |
| Vertical | Agriculture |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `field_sales`, `broker`, `logistics`, `gst`, `agriculture`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A licensed counter. Seeds, fertiliser and pesticides may only be sold against a valid licence, so the compliance register is opened before a season and before an inspection — and the farmer buys on credit until harvest.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Licences expiring in 60 days — derived on read, so it is always current
- Farmer credit outstanding, by village
- Season stock: what must move before the sowing window closes
- Batch expiry on chemicals

## 2. 🌾 Industry menu group — **Agri Centre**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-sun`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Input Licences | `/agri-licences` | `agriculture` (Business) | `agri-licences.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |
| Collections | `/salesman-collections` | `field_sales` (Business) | `salesman-collections.view` |
| Tills | `/pos-counters` | `pos` (Starter) | `pos-counters.view` |

- Input Licences leads and is the whole reason the vertical exists as its own module.
- Farmer credit uses core credit control — a farmer's ledger is a customer ledger with a harvest attached.

## 3. 🌾 Agri Centre / Agro Dealer-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Input licences — ✅ `/agri-licences` · `agriculture` — seed, fertiliser, pesticide; expiry derived on read
- Agri product attributes — crop, formulation, dosage on the product master
- Batch & expiry — ✅ `/batches` · `inventory`
- Farmer credit — ✅ `/credit-control` · `distribution` — settled at harvest
- Village routes / beats — ✅ `/beats` · `field_sales`
- Field collections — ✅ `/salesman-collections` · `field_sales`
- Counter POS — ✅ `/pos` · `pos`
  - Tills — ✅ `/pos-counters` · `pos`
- Weighing scale — ✅ `/devices` · `devices`
- Transport / delivery — ✅ `/delivery-routes` · `logistics`
- Soil health card — ⬜ declared `planned`
- Subsidy claim / DBT — ⬜ declared `planned`
- Farmer master with land holding — ⬜ farmers are customers today

## 4. Changes to the base

- Licences are promoted above everything; Loyalty is irrelevant here.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution` — farmer credit
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Licence expiry register — ⬜ read from the licence screen
- Crop / season-wise sales — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai` — season-blind today
- Dead stock — ✅ `/ai-insights` · `ai`
- Season demand forecast — ⬜

## 7. 📱 On the counter & on the phone

- Weighing scale and barcode at the counter (`devices`).
- SMS to farmers for credit reminders.

Mobile app menu for this trade: POS · Stock · Farmer credit · Collections · Licences

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `field_sales`, `broker`, `logistics`, `gst`, `agriculture`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, accounts — no licence register.
- **Business** — the working tier (`agriculture`, `field_sales`), or Starter + **Agri Inputs Pack**.
- **Professional** — channels.
- **Enterprise** — multi-branch.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `soil_health_card`
- `subsidy_claim`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

