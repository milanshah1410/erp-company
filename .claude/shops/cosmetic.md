# 💄 Cosmetic / Beauty Store

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `cosmetic` |
| Vertical | Beauty & Personal Care |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `cosmetics`, `loyalty`, `devices` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

A shade is not a colour: it is an identity a customer knows by number and will not substitute. And make-up has a second clock — a mascara dies six months after the seal is broken, and the units being opened are the testers on the counter.

Expiry here is a **till refusal**, driven by three tenant settings — selling expired make-up is an offence, not a markdown decision.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Shelf life: what dies this month, and what is already past
- Testers open and their PAO clock
- Shade gaps — which of the forty are out
- Membership redemptions

## 2. 💄 Industry menu group — **Beauty & Personal Care**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-heart-fill`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Shade Master | `/cosmetics/shades` | `cosmetics` (Enterprise) | `cosmetic-shades.view` |
| Shade Card | `/cosmetics/shade-card` | `cosmetics` (Enterprise) | `cosmetic-shades.view` |
| Testers | `/cosmetics/testers` | `cosmetics` (Enterprise) | `cosmetic-shelf-life.view` |
| Shelf Life | `/cosmetics/shelf-life` | `cosmetics` (Enterprise) | `cosmetic-shelf-life.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Memberships | `/memberships` | `cosmetics` (Enterprise) | `memberships.view` |
| Membership Plans | `/membership-plans` | `loyalty` (Starter) | `memberships.view` |

- Shelf Life leads, not the shade master: shades are written once and read forever, while the dating board is the one screen here with a clock in it.

## 3. 💄 Cosmetic / Beauty Store-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Shelf life — ✅ `/cosmetics/shelf-life` · `cosmetics` — printed expiry and PAO together
- Testers — ✅ `/cosmetics/testers` · `cosmetics` — the units that actually get opened
- Shade master — ✅ `/cosmetics/shades` · `cosmetics` — family, finish, undertone, depth; feeds `product_variants`
- Shade card — ✅ `/cosmetics/shade-card` · `cosmetics` — the counter's grid
- Batch & expiry — ✅ `/batches` · `inventory`
- Membership — ✅ `/memberships` · `cosmetics`
  - Plans — ✅ `/membership-plans` · `loyalty`
- Loyalty & offers — ✅ `/loyalty` · `loyalty`
- Gift vouchers — ✅ `/gift-vouchers` · `loyalty`
- Counter POS — ✅ `/pos` · `pos` — refuses an expired unit
- Barcode & label — ✅ `/label-printing`
- Ingredient / allergen list — ⬜ declared `planned`
- Recall notices — ⬜ declared `planned`

## 4. Changes to the base

- Batch & expiry promoted; Memberships pulled into the trade group.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Shade-wise sell-through — ⬜
- Tester cost per counter — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Barcode scanning and label printing (`devices`).

Mobile app menu for this trade: Shade lookup · Shelf life · POS · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `cosmetics`, `loyalty`, `devices`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — POS, loyalty, batches — no shade master, no PAO clock.
- **Business** — GST, HR — still no `cosmetics`.
- **Professional** — channels.
- **Enterprise** — the `cosmetics` module itself — Enterprise-only today.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `ingredient_allergens`
- `recall_notices`

- ⚠️ **Pricing gap.** `cosmetics` is in no tier and no marketplace pack, so the shade card and the expiry refusal are Enterprise-only. Same problem as `garment` and `supermarket`.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

