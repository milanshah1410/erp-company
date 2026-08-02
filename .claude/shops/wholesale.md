# 📦 Wholesale / Distribution

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `wholesale` |
| Vertical | Distribution & Wholesale |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `field_sales`, `distribution`, `broker`, `logistics`, `gst` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A wholesaler does not go under from lack of sales. It goes under from supplying a party that was already three months late — which is why credit control, not billing, is the centre of this menu.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Outstanding by days past due — the number the owner opens the day on
- Parties on supply hold
- Today's collections against target
- Salesman-wise sales and collection
- Slab-price exceptions granted this week

## 2. 📦 Industry menu group — **Distribution**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-truck`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| B2B Orders | `/b2b-orders` | `sales` (Starter) | `b2b-orders.view` |
| Outstanding & Ageing | `/reports/receivables-ageing` | `distribution` (Business)<br>plan feature `reports` | `reports.view` |
| Brokers | `/brokers` | `broker` (Business) | `brokers.view` |
| Broker Ledger | `/brokers/ledger` | `broker` (Business) | `brokers.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |
| Collections | `/salesman-collections` | `field_sales` (Business) | `salesman-collections.view` |
| Transporters | `/transporters` | `logistics` (Business) | `transporters.view` |
| Transport Charges | `/transport-charges` | `logistics` (Business) | `transport-charges.view` |
| E-Way Bills | `/e-way-bills` | `gst` (Business) | `e-way-bills.view` |

- Credit Control is under Sales in the catalogue because it is a supply decision — "does this order go out?" — asked by the desk that takes the order.

## 3. 📦 Wholesale / Distribution-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Credit control — ✅ `/credit-control` · `distribution` — limits, terms, supply hold
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution` — by days past due
- B2B / bulk orders — ✅ `/b2b-orders` · `sales` — portal orders needing approval
- Slab pricing — ✅ `/price-lists` — quantity-break rates per party category
- Broker (dalal) — ✅ `/brokers` · `broker`
  - Broker ledger — ✅ `/brokers/ledger` · `broker`
  - Commission — ✅ `/reports/broker-commissions` · `broker`
- Salesman — ✅ `/salesmen` · `field_sales`
  - Beats & routes — ✅ `/beats` · `field_sales`
  - Collections — ✅ `/salesman-collections` · `field_sales`
  - Targets — ✅ `/salesman-targets` · `field_sales`
- Transport
  - Transporters — ✅ `/transporters` · `logistics`
  - Transport charges — ✅ `/transport-charges` · `logistics`
  - Vehicles — ✅ `/vehicles` · `logistics`
- E-way bill — ✅ `/e-way-bills` · `gst`
- Multi-warehouse — ✅ `/warehouses`
  - Stock transfer — ✅ `/stock-transfers` · `inventory`
- Import / export — ✅ `/import-export` · `logistics` — overseas parties are flags on suppliers & customers
- Scheme / trade discount register — ⬜ handled as coupons and price lists

## 4. Changes to the base

- POS is dropped — a wholesaler bills against an order, not at a counter.
- Credit Control and Outstanding are promoted to the top of Sales.

## 5. 📈 Reports this trade needs

Base reports plus:

- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Broker commissions — ✅ `/reports/broker-commissions` · `broker`
- Broker-wise sales — ✅ `/reports/broker-wise-sales` · `broker`
- Sales — ✅ `/reports/sales` · `sales`
- Payables — ✅ `/reports/payables` · `purchase`
- Party-wise credit utilisation — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Collection-risk scoring by party — ⬜ ageing is a report, not a score
- Route / beat productivity — ⬜

## 7. 📱 On the counter & on the phone

- Salesmen carry the mobile app for orders and collections.
- E-way bill generation on dispatch (offline mode + GSP seam).

Mobile app menu for this trade: Orders · Party outstanding · Collections · Beat plan · Stock check

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `field_sales`, `distribution`, `broker`, `logistics`, `gst`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — sales, purchase, inventory, accounts — no credit discipline.
- **Business** — the working tier: `distribution`, `field_sales`, `broker`, `logistics`, `gst`. Or Starter + **Distribution & Wholesale Pack**.
- **Professional** — adds channels for a distributor selling online too.
- **Enterprise** — multi-depot, API access.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

