# 🎁 Gift Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `gift_shop` |
| Vertical | Gift & Novelty |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `gift`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Nobody buys anything for themselves. It has to be wrapped (a job with a queue and a promised time), it is often a hamper (assembled out of other stock), and it is bought for a date belonging to somebody who is not the customer.

Note the difference from a grocery combo: a hamper is **assembled**, so twenty built on Tuesday is twenty new articles and twenty fewer of everything inside them.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Wrap desk queue and parcels promised for this evening
- Hampers to build against orders
- Occasions coming up with lead time
- Wrap material running out
- Voucher liability outstanding

## 2. 🎁 Industry menu group — **Gift**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-gift`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Hamper Packs | `/gift/packs` | `gift` (Starter) | `gift-packs.view` |
| Wrap Desk | `/gift/wraps` | `gift` (Starter) | `gift-wraps.view` |
| Wrapping Options | `/gift/wrap-options` | `gift` (Starter) | `gift-wraps.view` |
| Gift Occasions | `/gift/occasions` | `gift` (Starter) | `gift-occasions.view` |
| Occasions | `/crm/occasions` | `crm` (Starter) | `customers.view` |

- The wrap desk leads: in the fortnight that matters it is the shop's bottleneck and the only screen with a queue on it.
- Gift Occasions (other people's dates, with lead time and budget) is deliberately labelled apart from CRM Occasions (the customer's own birthday).

## 3. 🎁 Gift Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Wrap desk — ✅ `/gift/wraps` · `gift` — queue, promised time, material consumed
  - Wrapping options — ✅ `/gift/wrap-options` · `gift`
- Hamper packs — ✅ `/gift/packs` · `gift` — assembled; moves stock at build time
- Gift occasions — ✅ `/gift/occasions` · `gift` — per person, with lead time and budget
- CRM occasions — ✅ `/crm/occasions` · `crm` — the customer's own dates
- Gift vouchers — ✅ `/gift-vouchers` · `loyalty` — core; money already taken
- Loyalty — ✅ `/loyalty` · `loyalty`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Product variants — ✅ `/products`
- Corporate / bulk hamper contract — ⬜
- Personalised message card printing — ⬜

## 4. Changes to the base

- Offers & Loyalty is promoted; vouchers matter more here than points.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Wrap-desk throughput — ⬜
- Occasion conversion (reminded → sold) — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Label printer for gift tags (`devices`).
- WhatsApp occasion reminders — the trade's best use of it.

Mobile app menu for this trade: Wrap queue · Occasions · POS · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `gift`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — the whole trade — `gift`, `pos`, `loyalty`, `devices` are all Starter, deliberately.
- **Business** — GST, HR for a bigger shop.
- **Professional** — channels.
- **Enterprise** — multi-store.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

