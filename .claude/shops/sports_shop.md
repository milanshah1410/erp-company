# 🏏 Sports & Fitness Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `sports_shop` |
| Vertical | Sports & Fitness |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `garment`, `gst`, `service`, `sports`, `loyalty`, `warranty`, `devices` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Everything a sports shop sells is already core — brands, the size × colour matrix, warranty, barcodes, a repair bench. What none of them can express is an article that **leaves the shop and is expected back**: earning by the day, wearing out a little each trip, held against a deposit that is the customer's money.

Deposits never touch the sales spine, and an unreturned line blocks availability until it is physically back.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Kit out on hire today, and overdue
- Deposits held (a liability, not revenue)
- Fleet utilisation this month
- Repairs on the bench
- Season stock (cricket in March, fitness in January)

## 2. 🏏 Industry menu group — **Sports & Fitness**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-flag`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Hire Fleet | `/sports/fleet` | `sports` (Business) | `rental-assets.view` |
| Hire Desk | `/sports/rentals` | `sports` (Business) | `rentals.view` |
| Fleet Utilisation | `/sports/utilisation` | `sports` (Business) | `rental-assets.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |

- The Hire Desk leads and the Fleet sits under it — the opposite of the usual register-then-document order, because the fleet is written a handful of times a season and the desk is worked every day.

## 3. 🏏 Sports & Fitness Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Hire desk — ✅ `/sports/rentals` · `sports` — bookings, partial returns, late fees
- Hire fleet — ✅ `/sports/fleet` · `sports` — rates, deposits, condition
- Fleet utilisation — ✅ `/sports/utilisation` · `sports` — read once a quarter, decides next season's buy
- Deposits — held against the contract; never on the sales spine
- Warranty cards — ✅ `/warranties` · `warranty`
- Repair jobs — ✅ `/service-jobs` · `service` — restring, wheel true
- Size & colour matrix — ✅ `/garment/matrix` · `garment` — needs `garment`
- Brands — ✅ `/brands`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Court / turf time booking — ⬜ declared `planned`; hiring time is a different problem
- Team kitting (bulk, with names and numbers) — ⬜ declared `planned`

## 4. Changes to the base

- The hire desk is promoted above POS — it is the part of the day with a clock on it.

## 5. 📈 Reports this trade needs

Base reports plus:

- Fleet utilisation — ✅ `/sports/utilisation` · `sports`
- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Hire revenue vs. fleet cost — ⬜
- Late-fee recovery — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Barcode on hire kit for check-in / check-out (`devices`).
- SMS overdue reminders.

Mobile app menu for this trade: Hire desk · Returns due · POS · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `garment`, `gst`, `service`, `sports`, `loyalty`, `warranty`, `devices`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — POS, inventory, loyalty, warranty — a shop with no hire desk.
- **Business** — the working tier: `sports` needs `service` beside it, and both are Business.
- **Professional** — channels.
- **Enterprise** — the size × colour matrix (`garment`), multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `court_booking`
- `team_kitting`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

