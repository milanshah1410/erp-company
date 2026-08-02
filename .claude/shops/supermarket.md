# 🏬 Supermarket

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `supermarket` |
| Vertical | Grocery & Kirana |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `supermarket`, `gst`, `grocery`, `loyalty`, `devices` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

A kirana with aisles, several lanes and staff who do not own the place. What is peculiar is not the billing but what is behind it: a shelf a customer picks off, a planogram that says what belongs on it, and a lane with nobody standing at it.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Counter-wise sales and cash-up variance per till
- Self-checkout lanes: baskets in progress, interventions
- Replenishment round: shelves below facing
- Membership sign-ups and redemptions
- Expiry in the next 30 days

## 2. 🏬 Industry menu group — **Supermarket**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-building`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Tills | `/pos-counters` | `pos` (Starter) | `pos-counters.view` |
| Shift Cash-Up | `/pos-counters/sessions` | `pos` (Starter) | `pos-counters.view` |
| Self-Checkout | `/supermarket/self-checkout` | `supermarket` (Enterprise) | `self-checkout.view` |
| Aisles & Shelves | `/supermarket/shelves` | `supermarket` (Enterprise) | `stock.view` |
| Shelf Planogram | `/supermarket/planogram` | `supermarket` (Enterprise) | `shelf-planogram.view` |
| Replenishment | `/supermarket/replenishment` | `supermarket` (Enterprise) | `stock.view` |
| Memberships | `/memberships` | `cosmetics` (Enterprise) | `memberships.view` |
| Membership Plans | `/membership-plans` | `loyalty` (Starter) | `memberships.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |

- Aisles & Shelves leads: it is the screen a store manager opens in the morning and the one the replenishment round is walked off. The planogram behind it is edited once a season.
- Multi-counter cash-up is core POS keyed on `payments`, not a supermarket feature — a two-till kirana reconciles the same way.

## 3. 🏬 Supermarket-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Multi-counter POS — ✅ `/pos-counters` · `pos` — tills defined once
- Shift cash-up — ✅ `/pos-counters/sessions` · `pos` — counted per desk, per shift
- Self-checkout — ✅ `/supermarket/self-checkout` · `supermarket` — a staging basket, not a second till
- Aisles & shelves — ✅ `/supermarket/shelves` · `supermarket` — `warehouse_locations` underneath
- Planogram — ✅ `/supermarket/planogram` · `supermarket` — what belongs on the facing
- Replenishment round — ✅ `/supermarket/replenishment` · `supermarket`
- Membership — ✅ `/memberships` · `cosmetics`
  - Membership plans — ✅ `/membership-plans` · `loyalty`
- Coupons — ✅ `/coupons` · `loyalty`
- Gift cards — ✅ `/gift-vouchers` · `loyalty`
- Batch & expiry — ✅ `/batches` · `inventory`
- Stock transfer between stores — ✅ `/stock-transfers` · `inventory`
- Weighing scale & barcode — ✅ `/devices` · `devices`
- Shelf-edge label printing — ✅ `/label-printing`
- Shrinkage / wastage register — ⬜ posted as stock adjustments today

## 4. Changes to the base

- Counters replace POS as the first Sales row — a hypermarket manages lanes, not one till.
- Loyalty is promoted: membership is the trade's main customer instrument.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Counter-wise / shift-wise sales — ⬜ sessions hold it; no report over them
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Category-wise margin — ✅ `/reports/profitability` · `sales`
- Shelf / planogram compliance — ⬜

## 6. 🤖 AI features

- Low stock and replenishment prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Basket / affinity analysis — ⬜

## 7. 📱 On the counter & on the phone

- Several scanners, scales and thermal printers per store (`devices`, one row per physical unit).
- Self-checkout lanes are software over the same POS spine.

Mobile app menu for this trade: Dashboard · Shelf check · Replenishment · Stock · Reports

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `supermarket`, `gst`, `grocery`, `loyalty`, `devices`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — one till, `grocery` combo packs, loyalty. No aisles.
- **Business** — GST, HR, logistics, distribution credit — a growing store.
- **Professional** — channels and production.
- **Enterprise** — the `supermarket` module itself (shelves, planogram, self-checkout) — Enterprise-only today.

## 9. ⬜ Not buildable into the menu yet

- ⚠️ **Pricing gap.** `supermarket` is in no tier and no pack, so shelves, planogram and self-checkout are Enterprise-only.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

