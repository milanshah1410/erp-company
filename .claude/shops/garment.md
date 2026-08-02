# 👕 Garment / Apparel

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `garment` |
| Vertical | Retail |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `garment`, `tailoring`, `gst`, `loyalty`, `devices` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

A garment counter sells a *style*, not a product: one design number becomes forty sellable SKUs once size and colour are crossed. Everything in this trade follows from that, plus the darji book behind the shop.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Size-wise and colour-wise sales for the week — the buy for next season is made off this
- Broken size runs — a style with the middle sizes gone is dead stock at full price
- Trial room: garments out, garments not returned
- Alterations promised today, and overdue
- Season / collection sell-through

## 2. 👕 Industry menu group — **Garment**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-shopping-bag`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Style Master | `/garment/styles` | `garment` (Enterprise) | `garment-styles.view` |
| Size Runs | `/garment/size-sets` | `garment` (Enterprise) | `garment-styles.view` |
| Size & Colour Matrix | `/garment/matrix` | `garment` (Enterprise) | `products.view` |
| Colours | `/garment/colours` | `garment` (Enterprise) | `garment-styles.view` |
| Fabrics | `/garment/fabrics` | `garment` (Enterprise) | `garment-fabrics.view` |
| Trial Room | `/garment/trials` | `garment` (Enterprise) | `garment-trials.view` |
| Alterations | `/tailor-alterations` | `garment` (Enterprise) | `tailor-alterations.view` |
| Tailor Master | `/tailors` | `tailoring` (Enterprise) | `tailors.view` |
| Pending Work | `/tailor-works/pending` | `tailoring` (Enterprise) | `tailor-works.view` |
| Completed Work | `/tailor-works/completed` | `tailoring` (Enterprise) | `tailor-works.view` |
| Payment | `/tailor-payments` | `tailoring` (Enterprise) | `tailor-payments.view` |

- Style Master leads: nothing else in the group means anything until the range exists.
- Alterations is a second door onto the Tailoring screen, so a showroom that runs `garment` without the workroom's nav still gets its darji book.

## 3. 👕 Garment / Apparel-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Style / design master — ✅ `/garment/styles` · `garment` — design no., season, fit, MRP
- Size & colour matrix — ✅ `/garment/matrix` · `garment` — one grid, writes plain `product_variants`
  - Size runs (XS–XXXL, and numeric) — ✅ `/garment/size-sets` · `garment`
  - Colour master — ✅ `/garment/colours` · `garment`
- Fabric register — ✅ `/garment/fabrics` · `garment` — composition, width, supplier, rate
- Trial room — ✅ `/garment/trials` · `garment` — reserves stock, does not move it
- Alteration (દરજી) book — ✅ `/tailor-alterations` · `garment`
  - Tailor master — ✅ `/tailors` · `tailoring`
  - Pending work — ✅ `/tailor-works/pending` · `tailoring`
  - Completed work — ✅ `/tailor-works/completed` · `tailoring`
  - Tailor payment — ✅ `/tailor-payments` · `tailoring`
  - Piece rates — ✅ `/piece-rates` · `tailoring`
- Barcode & price tag — ✅ `/label-printing` — four templates: barcode, QR, sticker, price tag
- Gift voucher — ✅ `/gift-vouchers` · `loyalty`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Collection / season / gender / age group — ⬜ custom fields or categories; no first-class register
- Sale-price / MRP change history — ⬜ price lists hold the current slab only

## 4. Changes to the base

- Products group gains Style Master and the matrix as the primary way in — `/products` becomes the fallback, not the front door.
- Nothing removed.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales` — group by brand / category from the filter bar
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Dead stock — ✅ `/dead-stock` · `inventory`
- Tailor performance — ✅ `/reports/tailor` · `tailoring`
- Size-wise sales — ⬜ the buy depends on it; today it is a matrix read, not a report
- Colour-wise sales — ⬜ same
- Sell-through by season / collection — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Suggested purchase order — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling size / colour — ⬜ needs the size-wise cut above
- Seasonal demand prediction — ⬜ engine is generic; not season-aware

## 7. 📱 On the counter & on the phone

- Barcode scanner + label printer at the counter (`devices`) — a garment shop tags every piece.
- WhatsApp for alteration-ready and trial-return reminders.

Mobile app menu for this trade: Dashboard · POS · Matrix stock check · Trials · Alterations · Customers

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `garment`, `tailoring`, `gst`, `loyalty`, `devices`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — POS, products, inventory, sales, purchase, customers, loyalty, barcode. No matrix — `garment` is not in this tier.
- **Business** — adds GST, HR, service; the alteration book needs `tailoring`, which is above this tier.
- **Professional** — adds e-commerce and production; still no `garment`.
- **Enterprise** — the whole preset — `garment` and `tailoring` are Enterprise-only. This is the pricing problem noted below.

## 9. ⬜ Not buildable into the menu yet

- ⚠️ **Pricing gap.** `garment` sits in no plan tier and no marketplace pack, so the style master and matrix are Enterprise-only. The trade this vertical was written for is a Business-sized showroom.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

