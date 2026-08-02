# ⚡ Electrical Goods Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `electrical_shop` |
| Vertical | Electronics & Appliances |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `electronics`, `warranty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

A counter with cover: switches, wire, fans and lights sold by the piece and the metre, with serial-backed warranty on the few items that carry it. No workshop, which is what separates it from its two sibling trades.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Warranty claims open
- Fast movers by brand
- Wire and cable stock by metre
- Counter sales and returns

## 2. ⚡ Industry menu group — **Electrical**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-bolt`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Warranty Claims | `/warranties/claims` | `warranty` (Starter) | `warranty-claims.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |

- Four rows only: the preset carries `electronics` and `warranty` but not `service`, so the job-card row is a courtesy door for shops that also run a bench.

## 3. ⚡ Electrical Goods Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Serial numbers — ✅ `/serial-numbers` · `inventory`
- Warranty cards — ✅ `/warranties` · `warranty`
  - Claims — ✅ `/warranties/claims` · `warranty`
- Job cards — ✅ `/service-jobs` · `service` — only if `service` is switched on
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Devices — ✅ `/devices` · `devices`
- Watt / voltage / rating attributes — ⬜ declared `planned` as electrical specs; custom fields today
- Wire sold by the metre — unit of measure on the product
- Contractor / electrician account — a customer category with slab pricing

## 4. Changes to the base

- Small menu — closest of any trade to the plain retail base.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Brand-wise sales — ✅ `/reports/sales` · `sales` — filter grouping
- Contractor-wise sales — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Barcode scanner, thermal printer (`devices`).

Mobile app menu for this trade: POS · Stock check · Customers · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `electronics`, `warranty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty — genuinely workable at this tier.
- **Business** — adds `electronics` (serial register) and GST.
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `electrical_specs`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

