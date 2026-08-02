# 🥛 Dairy / Milk Distribution

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `dairy` |
| Vertical | Food & Beverage |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `field_sales`, `logistics`, `gst`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Not a shop — a round. The morning walk is the business: standing orders delivered before dawn, collection from farmers at the other end, and a bill raised monthly rather than per sale.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Today's round: delivered / missed / extra
- Milk collected today (litres, fat/SNF)
- Subscription changes taking effect tomorrow
- Monthly billing run status
- Expiry on packaged stock

## 2. 🥛 Industry menu group — **Dairy**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-truck`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Milk Round | `/milk-round` | `sales` (Starter) | `milk-round.view` |
| Subscriptions | `/milk-subscriptions` | `sales` (Starter) | `milk-subscriptions.view` |
| Milk Collection | `/milk-collections` | `purchase` (Starter) | `milk-collections.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |

- The round leads: it is the screen walked every morning. Then the standing orders behind it, then the collection counter.

## 3. 🥛 Dairy / Milk Distribution-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Milk round — ✅ `/milk-round` · `sales` — the daily delivery sheet
- Subscriptions — ✅ `/milk-subscriptions` · `sales` — standing orders, pause and resume
- Milk collection — ✅ `/milk-collections` · `purchase` — from farmers, in
- Routes & beats — ✅ `/beats` · `field_sales` — who walks what
- Delivery routes — ✅ `/delivery-routes` · `logistics`
- Batch & expiry — ✅ `/batches` · `inventory`
- Counter POS — ✅ `/pos` · `pos` — for walk-ins
- Monthly subscription billing — generated from the round
- Fat / SNF rate chart — ⬜ collection records quantity; a rate chart by quality is not built
- Can / crate deposit tracking — ⬜

## 4. Changes to the base

- POS is secondary — most revenue never touches a till.
- Field Sales and Logistics are promoted: routes are the operational spine.
- Loyalty is irrelevant; drop the group.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Receivables ageing — ✅ `/reports/receivables-ageing` · `distribution` — monthly bills run late by nature
- Route-wise delivery — ⬜
- Collection register by farmer — ⬜

## 6. 🤖 AI features

- Low stock / ingredient shortfall — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Best-selling dish — ✅ `/ai-insights` · `ai` — reads as best-selling product
- Wastage prediction — ⬜

## 7. 📱 On the counter & on the phone

- Route sheets print at the depot; the delivery boy carries the mobile app.

Mobile app menu for this trade: Round sheet · Deliveries · Collections · Customers · Collections due

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `field_sales`, `logistics`, `gst`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory; no round.
- **Business** — the working tier — `field_sales` and `logistics` carry the round and the beats.
- **Professional** — not needed unless the dairy processes.
- **Enterprise** — multi-depot.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

