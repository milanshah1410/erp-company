# 🍽️ Home Appliance & Kitchenware

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `home_appliance` |
| Vertical | Electronics & Appliances |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `logistics`, `gst`, `service`, `electronics`, `loyalty`, `warranty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

The same install base and service desk as an electronics showroom, weighted differently: almost every sale is delivered and installed, so logistics is a daily group rather than an occasional one.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Deliveries and installations scheduled today
- Open service requests
- AMC contracts expiring
- Warranty claims in progress

## 2. 🍽️ Industry menu group — **Appliances**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-home`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Warranty Claims | `/warranties/claims` | `warranty` (Starter) | `warranty-claims.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| AMC Contracts | `/service-contracts` | `service` (Business) | `service-contracts.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Proof of Delivery | `/proof-of-delivery` | `logistics` (Business) | `dispatch-pods.view` |

- Delivery and POD are in the trade group because an appliance sale is not finished until it is installed and signed for.

## 3. 🍽️ Home Appliance & Kitchenware-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Serial numbers — ✅ `/serial-numbers` · `inventory`
- Units & install base — ✅ `/electronics/units` · `electronics`
- Warranty cards — ✅ `/warranties` · `warranty`
  - Claims — ✅ `/warranties/claims` · `warranty`
- Service requests — ✅ `/electronics/requests` · `electronics`
- Job cards — ✅ `/service-jobs` · `service`
- AMC contracts — ✅ `/service-contracts` · `service`
- Delivery routes — ✅ `/delivery-routes` · `logistics`
  - Proof of delivery — ✅ `/proof-of-delivery` · `logistics`
- Installation visit — a service job
- Exchange / old-appliance buyback — POS exchange; valuation grid is ⬜
- Counter POS — ✅ `/pos` · `pos`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Demo & display stock — ⬜

## 4. Changes to the base

- Logistics promoted into the daily menu.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales`
- Delivery / installation completion — ⬜
- AMC renewal pipeline — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Serial scanning; POD capture on the phone.

Mobile app menu for this trade: Deliveries / POD · Installations · Service requests · Stock · POS

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `logistics`, `gst`, `service`, `electronics`, `loyalty`, `warranty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty.
- **Business** — the working tier (`electronics`, `service`, `logistics`).
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `buyback_valuation`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

