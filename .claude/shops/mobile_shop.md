# 📱 Mobile Phone Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `mobile_shop` |
| Vertical | Mobile & Handsets |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `service`, `mobile`, `loyalty`, `warranty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

The handset register leads, and unlike its sibling verticals it is not a diary but a search box: a phone counter's day is a queue of people reading fifteen digits off the back of a handset, and every other screen is reachable only once that number resolves.

Warranty rides on the core register; insurance is separate only because cover gets used up. EMI is a receivable only when it is in-house, and a buyback settles as a tender.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- IMEI stock: in hand, sold today, in repair
- Insurance policies active and claims open
- EMI files and instalments due
- Part-exchange units awaiting refurbishment
- Accessory attach rate

## 2. 📱 Industry menu group — **Mobile**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-mobile`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Handsets & IMEI | `/mobile/handsets` | `mobile` (Business) | `mobile-handsets.view` |
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Warranty Claims | `/warranties/claims` | `warranty` (Starter) | `warranty-claims.view` |
| Insurance | `/mobile/insurance` | `mobile` (Business) | `mobile-insurance.view` |
| EMI Files | `/mobile/emi` | `mobile` (Business) | `mobile-emi.view` |
| Part-Exchange | `/mobile/buybacks` | `mobile` (Business) | `mobile-buyback.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |

- Warranty Cards and Repair Jobs are second doors onto the core registers — Mobile has no warranty register of its own, on purpose.

## 3. 📱 Mobile Phone Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Handsets & IMEI — ✅ `/mobile/handsets` · `mobile` — the search box the counter lives on
- Serial numbers — ✅ `/serial-numbers` · `inventory`
- Warranty cards — ✅ `/warranties` · `warranty`
  - Claims — ✅ `/warranties/claims` · `warranty`
  - Extended warranty — second card, own term
- Insurance — ✅ `/mobile/insurance` · `mobile` — screen / theft; cover gets used up
- EMI files — ✅ `/mobile/emi` · `mobile` — a receivable only when in-house
- Part-exchange (buyback) — ✅ `/mobile/buybacks` · `mobile` — settles as a POS tender
- Repair job cards — ✅ `/service-jobs` · `service`
- Accessories — ✅ `/products`
- Counter POS — ✅ `/pos` · `pos`
- Barcode / QR & label — ✅ `/label-printing`
- Loyalty — ✅ `/loyalty` · `loyalty`
- IMEI capture at the till — ⬜ declared `planned`; captured on the handset record today
- Insurance claim settlement — ⬜ declared `planned`

## 4. Changes to the base

- Warranty and Service are both promoted into the trade group.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales` — handset margin is thin; accessories carry it
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- IMEI movement register — ⬜
- EMI collection ageing — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- IMEI barcode scanning is the single most-used device (`devices`).
- SMS / WhatsApp when a repair is ready.

Mobile app menu for this trade: IMEI lookup · POS · Repairs · Insurance · Stock

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `service`, `mobile`, `loyalty`, `warranty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty — no IMEI register.
- **Business** — the working tier (`mobile`, `service`, `warranty`), or Starter + **Mobile & Handsets Pack**.
- **Professional** — channels.
- **Enterprise** — multi-store chains.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `pos_imei_capture`
- `insurance_claim_settlement`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

