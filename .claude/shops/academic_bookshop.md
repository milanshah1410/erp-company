# 🎓 Academic & School Bookshop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `academic_bookshop` |
| Vertical | Books & Periodicals |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `ecommerce`, `gst`, `stationery`, `books`, `loyalty`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

A book store and a stationery shop in one: the ISBN catalogue for titles, and the school-supply machinery for the ten weeks a year when the customer is an institution.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Booklists published vs. sets sold
- Title stock against the class lists
- School orders pending
- Season demand for the next fortnight

## 2. 🎓 Industry menu group — **Books**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-book`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Titles | `/books/titles` | `books` (Starter) | `book-catalogue.view` |
| Authors | `/books/authors` | `books` (Starter) | `book-catalogue.view` |
| Publishers | `/books/publishers` | `books` (Starter) | `book-catalogue.view` |
| Book Languages | `/books/languages` | `books` (Starter) | `book-catalogue.view` |
| Institutions | `/stationery/institutions` | `stationery` (Business) | `stationery-institutions.view` |
| School Orders | `/stationery/school-orders` | `stationery` (Business) | `sales.view` |

- Both halves in one group rather than two: in season the shop does not think of them separately.

## 3. 🎓 Academic & School Bookshop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Titles — ✅ `/books/titles` · `books`
- Authors — ✅ `/books/authors` · `books`
- Publishers — ✅ `/books/publishers` · `books`
- Languages — ✅ `/books/languages` · `books`
- Institutions — ✅ `/stationery/institutions` · `stationery`
- Class booklists — ✅ `/stationery/class-kits` · `stationery`
- School orders — ✅ `/stationery/school-orders` · `stationery`
- Season demand — ✅ `/stationery/season-demand` · `stationery`
- Bulk price lists — ✅ `/price-lists`
- Online orders — ✅ `/channel-orders` · `ecommerce` — needs `ecommerce`
- Counter POS — ✅ `/pos` · `pos`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Pending-item slips — ⬜ declared `planned`
- Sale-or-return — ⬜ declared `planned`

## 4. Changes to the base

- Carries both `books` and `stationery`; the busiest menu of the two verticals.

## 5. 📈 Reports this trade needs

Base reports plus:

- Season demand — ✅ `/stationery/season-demand` · `stationery`
- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Class-wise fulfilment — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- ISBN scanning and label printing (`devices`).

Mobile app menu for this trade: ISBN lookup · Booklists · POS · Stock · School orders

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `ecommerce`, `gst`, `stationery`, `books`, `loyalty`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — the ISBN catalogue only.
- **Business** — adds `stationery` — the working tier for the school half.
- **Professional** — online orders.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `sale_or_return`
- `title_reservations`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

