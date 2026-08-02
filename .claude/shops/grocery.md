# 🛒 Grocery / Kirana

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `grocery` |
| Vertical | Grocery & Kirana |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `grocery`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

The smallest paying workspace this product has, and priced that way — `grocery` is the one vertical in Starter. Two ideas make the trade: a barcode that is three things at one price, and a ledger of what the shop owes its distributors.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Daily cash — counted twice a day, and the number the owner actually checks
- UPI collection for the day
- Supplier credit falling due today
- Expiry in the next 30 days
- Fast movers and shorts

## 2. 🛒 Industry menu group — **Kirana**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-shopping-cart`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Combo Packs | `/grocery/combo-packs` | `grocery` (Starter) | `products.view` |
| Supplier Credit | `/grocery/supplier-credit` | `grocery` (Starter) | `supplier-credit.view` |
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Tills | `/pos-counters` | `pos` (Starter) | `pos-counters.view` |
| Shift Cash-Up | `/pos-counters/sessions` | `pos` (Starter) | `pos-counters.view` |
| Devices | `/devices` | `devices` (Starter) | `devices.view` |

- Supplier Credit is the debtor's mirror of Credit Control, and it has its own permission because it is the owner's book of debts, not the shelf-pricer's.
- Combo packs explode into their components at the POS **stock** pass, not on the bill — one scan, one price, three items off the shelf.

## 3. 🛒 Grocery / Kirana-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- POS billing — ✅ `/pos` · `pos` — the trade's main screen
- Counters & daily cash-up — ✅ `/pos-counters/sessions` · `pos`
- Batch & expiry — ✅ `/batches` · `inventory` — expiry is a POS refusal, not a warning
- Combo / multipack — ✅ `/grocery/combo-packs` · `grocery` — one scan, components deplete
- Supplier credit — ✅ `/grocery/supplier-credit` · `grocery` — limits, terms, what must be paid today
- Customer credit (udhaar) — ✅ `/credit-control` · `distribution`
- Weighing scale — ✅ `/devices` · `devices` — loose grain, dal, sugar
- Barcode scanner & label printer — ✅ `/devices` · `devices`
- Offers & coupons — ✅ `/coupons` · `loyalty`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Home delivery round — ⬜ `logistics` delivery routes exist but are not in this preset

## 4. Changes to the base

- POS is the whole Sales group in practice — quotations and B2B orders can be dropped from a single-counter shop's menu.
- Batch and expiry are promoted out of Inventory into the daily group.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory` — the expiry read
- Payables — ✅ `/reports/payables` · `purchase`
- Daily cash book — ✅ `/accounts/cash-book` · `accounts`
- Expiry-due register — ⬜ read from batches today

## 6. 🤖 AI features

- Low stock alert — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock report — ✅ `/ai-insights` · `ai`
- Expiry-risk purchase advice — ⬜

## 7. 📱 On the counter & on the phone

- Weighing scale, barcode scanner and thermal printer are the three that matter (`devices`, per-device simulator mode for dev).
- UPI QR collection at the counter.

Mobile app menu for this trade: Dashboard · POS · Stock check · Purchase · Supplier credit · Daily cash

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `grocery`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — the whole trade — `grocery`, `pos`, `loyalty`, `devices` are all Starter. This is deliberate.
- **Business** — adds GST filing, HR and delivery routes for a shop that has grown.
- **Professional** — e-commerce / quick-commerce channels.
- **Enterprise** — multi-store.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

