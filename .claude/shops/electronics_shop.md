# 📺 Electronics Showroom

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `electronics_shop` |
| Vertical | Electronics & Appliances |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `logistics`, `gst`, `service`, `electronics`, `loyalty`, `warranty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Every unit has a number stamped on it, and everything afterwards hangs off that number: warranty scope, the installation visit, the service request and the claim. This vertical extends core `product_warranties` with parts/labour scope rather than shipping a second register — a shop must never be given two answers about who pays.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Open service requests and their age
- Installations scheduled today
- Warranty expiring this month (upsell to extended)
- Deliveries pending POD
- Serial-tracked stock

## 2. 📺 Industry menu group — **Electronics**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-desktop`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Units & Serials | `/electronics/units` | `electronics` (Business) | `electronics-units.view` |
| Service Requests | `/electronics/requests` | `electronics` (Business) | `electronics-requests.view` |
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Warranty Claims | `/warranties/claims` | `warranty` (Starter) | `warranty-claims.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| AMC Contracts | `/service-contracts` | `service` (Business) | `service-contracts.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Proof of Delivery | `/proof-of-delivery` | `logistics` (Business) | `dispatch-pods.view` |

- Requests lead for the same reason bookings do in a garage: the desk's diary is the only screen that says what today looks like before any of it has happened.

## 3. 📺 Electronics Showroom-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Units & serials — ✅ `/electronics/units` · `electronics` — the install base
- Service requests — ✅ `/electronics/requests` · `electronics` — the desk's diary
- Job cards — ✅ `/service-jobs` · `service`
- Warranty cards — ✅ `/warranties` · `warranty` — parts / labour scope
  - Claims — ✅ `/warranties/claims` · `warranty`
  - Extended warranty — a second card with its own term
- AMC contracts — ✅ `/service-contracts` · `service`
- Serial numbers — ✅ `/serial-numbers` · `inventory`
- Delivery — ✅ `/delivery-routes` · `logistics`
  - Proof of delivery — ✅ `/proof-of-delivery` · `logistics`
- Installation — a service job of type installation
- Exchange — POS action
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Buyback valuation grid — ⬜ declared `planned` (the mobile plugin has one)
- Demo / display stock tracking — ⬜

## 4. Changes to the base

- Service, Warranty and Logistics are all promoted — this trade is half shop, half after-sales operation.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Service-request turnaround — ⬜
- Warranty cost per model — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Serial / barcode scanning at inward and at sale (`devices`).
- Delivery POD on the driver's phone.

Mobile app menu for this trade: Service requests · Job cards · Delivery / POD · Stock · POS

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `logistics`, `gst`, `service`, `electronics`, `loyalty`, `warranty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty — a counter with cover but no service desk.
- **Business** — the working tier (`electronics`, `service`, `logistics`), or Starter + **Electronics & Appliances Pack**.
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `buyback_valuation`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

