# 📚 Book Store

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `book_store` |
| Vertical | Books & Periodicals |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `ecommerce`, `gst`, `books`, `loyalty`, `devices` |
| Minimum plan | **Professional** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

A book shop does not name its own stock: the article arrives with a worldwide identity (the ISBN), a price printed on the cover by somebody else, and people on the front that customers ask for by name.

The ISBN **is** the barcode — an observer mirrors it onto the product — so the core till becomes scannable without touching it, and the number is check-summed on the way in.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Titles added and sold this week
- Publisher-wise stock and trade discount
- Online orders waiting to be picked
- Slow titles for sale-or-return

## 2. 📚 Industry menu group — **Books**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-book`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Titles | `/books/titles` | `books` (Starter) | `book-catalogue.view` |
| Authors | `/books/authors` | `books` (Starter) | `book-catalogue.view` |
| Publishers | `/books/publishers` | `books` (Starter) | `book-catalogue.view` |
| Book Languages | `/books/languages` | `books` (Starter) | `book-catalogue.view` |
| Marketplace Orders | `/channel-orders` | `ecommerce` (Professional)<br>plan feature `integrations` | `channel-orders.view` |
| Integrations | `/ecommerce-integrations` | `ecommerce` (Professional)<br>plan feature `integrations` | `ecommerce-integrations.view` |

- ⚠️ **Plan-tier trap.** `books` is Starter but `ecommerce` is Professional, so the two channel rows in this template are only visible to a workspace whose plan carries `ecommerce`. Do **not** add a second door onto the channel inbox from the Books group — that would grant `channel-orders` to every Starter book shop and then 403 them.

## 3. 📚 Book Store-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Titles — ✅ `/books/titles` · `books` — ISBN check-summed; mirrors onto the barcode
- Authors & translators — ✅ `/books/authors` · `books`
- Publishers — ✅ `/books/publishers` · `books` — trade discount, sale-or-return terms
- Languages — ✅ `/books/languages` · `books`
- Categories / genres — ✅ `/categories` — core
- Online orders — ✅ `/channel-orders` · `ecommerce` — needs `ecommerce`
  - Integrations — ✅ `/ecommerce-integrations` · `ecommerce`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Sale-or-return to publisher — ⬜ declared `planned`
- Title reservation for a customer — ⬜ declared `planned`
- Periodical / subscription copies — ⬜

## 4. Changes to the base

- Products group is led by Titles rather than `/products`.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Dead stock — ✅ `/dead-stock` · `inventory`
- Publisher-wise / language-wise sales — ⬜
- Sale-or-return liability — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- ISBN barcode scanning is the whole hardware story (`devices`).

Mobile app menu for this trade: ISBN lookup · POS · Stock · Online orders · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `ecommerce`, `gst`, `books`, `loyalty`, `devices`, so the
workspace needs **Professional** for the whole preset to switch on.

- **Starter** — the ISBN register, authors, publishers, languages — deliberately, because catalogue hygiene is worst at the smallest shops.
- **Business** — GST, HR.
- **Professional** — online orders (`ecommerce`) — the row this trade's feature map promises and this tier delivers.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `sale_or_return`
- `title_reservations`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

