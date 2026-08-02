# 📝 Stationery Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `stationery_shop` |
| Vertical | Stationery & School Supplies |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `logistics`, `gst`, `stationery`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

For ten weeks a year the customer is an *institution* that does not buy anything: a school publishes a class list, appoints a supplier, and sends four hundred parents to fetch it one at a time. The school is the catalogue owner, not the customer.

A booklist is not a BOM — nothing is manufactured, and the shop must be able to sell twenty-two items as one named set without inventing a product.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Booklists published vs. sets sold, by class
- Season demand: what will run out this week
- Pending items (parents waiting on a short line)
- School order fulfilment
- Bulk price slabs in use

## 2. 📝 Industry menu group — **Stationery**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-pencil`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Institutions | `/stationery/institutions` | `stationery` (Business) | `stationery-institutions.view` |
| Class Booklists | `/stationery/class-kits` | `stationery` (Business) | `stationery-kits.view` |
| School Orders | `/stationery/school-orders` | `stationery` (Business) | `sales.view` |
| Season Demand | `/stationery/season-demand` | `stationery` (Business) | `stationery-kits.view` |

- Booklists lead, because between April and June they *are* the shop: every question at the counter is "Class 5, Sunrise" and every answer starts on that screen.
- Bulk pricing was already core — `Customer::priceFor` quantity slabs — so the module stays narrow.

## 3. 📝 Stationery Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Institutions (schools) — ✅ `/stationery/institutions` · `stationery` — the catalogue owner
- Class booklists — ✅ `/stationery/class-kits` · `stationery` — session, class, item list
- School orders — ✅ `/stationery/school-orders` · `stationery` — a SalesOrder with channel `institution`
- Season demand — ✅ `/stationery/season-demand` · `stationery`
- Bulk price lists — ✅ `/price-lists` — quantity slabs, core
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Delivery to school — ✅ `/delivery-routes` · `logistics`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Pending-item slips (what a parent still has to collect) — ⬜ declared `planned`
- Printed booklist handout — ⬜ declared `planned`
- Tender / quotation to institutions — quotations, core

## 4. Changes to the base

- The institution screens sit above Customers — the school is not a customer record.

## 5. 📈 Reports this trade needs

Base reports plus:

- Season demand — ✅ `/stationery/season-demand` · `stationery`
- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Class-wise / school-wise fulfilment — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Barcode and label printing (`devices`).
- WhatsApp to parents when a pending item arrives — ⬜ manual today.

Mobile app menu for this trade: Booklists · POS · Stock check · School orders · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `logistics`, `gst`, `stationery`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, price slabs — a shop with no school accounts.
- **Business** — the working tier (`stationery` + `logistics`), or Starter + **Stationery & School Supplies Pack**.
- **Professional** — channels.
- **Enterprise** — multi-store, multi-school contracts.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `pending_item_slips`
- `printed_booklists`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

