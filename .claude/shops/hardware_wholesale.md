# 🔩 Hardware & Building Material

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `hardware_wholesale` |
| Vertical | Distribution & Wholesale |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `field_sales`, `distribution`, `broker`, `logistics`, `gst`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Building material: sold by weight or bundle, delivered by truck, and paid for whenever the site releases money. Transport is not an afterthought — it is a line on the bill.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Outstanding by party and by site
- Vehicles out and deliveries pending POD
- Transport charges recovered vs. paid
- Heavy / slow stock by weight

## 2. 🔩 Industry menu group — **Hardware & Transport**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-wrench`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Transporters | `/transporters` | `logistics` (Business) | `transporters.view` |
| Transport Charges | `/transport-charges` | `logistics` (Business) | `transport-charges.view` |
| Vehicles | `/vehicles` | `logistics` (Business) | `vehicles.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Proof of Delivery | `/proof-of-delivery` | `logistics` (Business) | `dispatch-pods.view` |

- Bundle stock and cut sizes have no screen of their own; what the trade genuinely needs beyond core is the transport desk, which is what this group is.

## 3. 🔩 Hardware & Building Material-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Credit control — ✅ `/credit-control` · `distribution`
- Transporters — ✅ `/transporters` · `logistics`
- Transport charges — ✅ `/transport-charges` · `logistics`
- Vehicles — ✅ `/vehicles` · `logistics`
- Delivery routes — ✅ `/delivery-routes` · `logistics`
- Proof of delivery — ✅ `/proof-of-delivery` · `logistics`
- Weighing scale — ✅ `/devices` · `devices`
- Broker — ✅ `/brokers` · `broker`
- Salesman & beats — ✅ `/beats` · `field_sales`
- E-way bill — ✅ `/e-way-bills` · `gst`
- Length / width / thickness / weight attributes — ⬜ custom fields today
- Bundle stock (pieces per bundle) — ⬜ handled with a second unit of measure
- Cut-to-size sale — ⬜

## 4. Changes to the base

- Transport is promoted to a daily group; POS is optional (many hardware wholesalers do bill at a counter).

## 5. 📈 Reports this trade needs

Base reports plus:

- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Sales — ✅ `/reports/sales` · `sales`
- Broker commissions — ✅ `/reports/broker-commissions` · `broker`
- Transport cost recovery — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Collection-risk scoring by party — ⬜ ageing is a report, not a score
- Route / beat productivity — ⬜

## 7. 📱 On the counter & on the phone

- Weighbridge / weighing scale (`devices`).
- POD captured on the driver's phone.

Mobile app menu for this trade: Orders · Outstanding · Delivery / POD · Stock check

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `field_sales`, `distribution`, `broker`, `logistics`, `gst`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — counter billing and books only.
- **Business** — the working tier (`distribution`, `logistics`, `broker`, `field_sales`).
- **Professional** — channels.
- **Enterprise** — multi-yard.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

