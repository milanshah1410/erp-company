# 👟 Footwear Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `footwear` |
| Vertical | Retail |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `garment`, `gst`, `loyalty`, `warranty`, `devices` |
| Minimum plan | **Enterprise** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Retail ERP as the base, footwear-specific modules on top. The trade shares the garment size × colour grid — a shoe is a style in eleven sizes and four colours — and adds the two things a shoe shop lives on that a garment shop does not: a warranty the customer walks back in with, and exchange as a routine rather than an exception.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Size-wise sales — the single most useful number in the trade; the reorder is made off it
- Brand-wise sales (Nike / Adidas / Puma / Bata / Woodland / local)
- Broken size runs by article
- Exchanges today, and the net of them
- Open warranty claims

## 2. 👟 Industry menu group — **Footwear**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-directions`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Size Runs | `/garment/size-sets` | `garment` (Enterprise) | `garment-styles.view` |
| Size & Colour Matrix | `/garment/matrix` | `garment` (Enterprise) | `products.view` |
| Colours | `/garment/colours` | `garment` (Enterprise) | `garment-styles.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Warranty Claims | `/warranties/claims` | `warranty` (Starter) | `warranty-claims.view` |

- The size × colour grid is `garment`'s and is reused verbatim — a second grid for shoes would be the same table with a different name.
- Warranty leads the claims book: the register is what gets looked up ("is this covered?"); the claim is only opened once the answer is yes.

## 3. 👟 Footwear-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Size management — ✅ `/garment/size-sets` · `garment` — one size run per scale
  - UK / EU / US / kids scales — ⬜ one run each today; a cross-scale conversion chart is not built
- Colour management — ✅ `/garment/colours` · `garment`
- Size × colour matrix — ✅ `/garment/matrix` · `garment` — the article grid; writes `product_variants`
- Brand — ✅ `/brands` — Nike, Adidas, Puma, Bata, Woodland, local
- Style (casual / formal / sports / sandal / sneaker / boot) — ⬜ category tree or a custom field
- Gender & age (men / women / kids / unisex) — ⬜ custom field or category
- Material (leather / PU / canvas / rubber / foam) — ⬜ custom field
- Warranty
  - Warranty cards — ✅ `/warranties` · `warranty` — issued against the sale, by date
  - Warranty claims — ✅ `/warranties/claims` · `warranty`
  - Replacement — settled as a claim outcome, then a POS exchange
- Exchange (size / colour / product) — POS action, not a screen — reverses points, vouchers and the warranty card
- Pair management (left / right, single-pair availability) — ⬜ stock is per article, not per foot
- Repair: sole change, stitching, polish — ⬜ needs `service` job cards; not in the footwear preset
- Barcode & price tag — ✅ `/label-printing`
- Loyalty & gift voucher — ✅ `/loyalty` · `loyalty`

## 4. Changes to the base

- Sales group: exchange is the busiest POS action here — keep POS first.
- Warranty is promoted out of the base into the industry group, because the claims desk is a daily queue for this trade.

## 5. 📈 Reports this trade needs

Base reports plus:

- Brand-wise sales — ✅ `/reports/sales` · `sales` — group by brand in the filter bar
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Dead stock — ✅ `/dead-stock` · `inventory`
- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Size-wise sales — ⬜ the trade's most-asked report
- Colour-wise sales — ⬜
- Exchange register — ⬜ exchanges are visible as POS documents, not as their own report

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Suggested purchase order — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling size / brand — ⬜ blocked on the size-wise cut
- Seasonal demand prediction — ⬜
- Customer buying pattern — ✅ `/ai-insights` · `ai` — generic, not shoe-aware

## 7. 📱 On the counter & on the phone

- Barcode scanner + thermal printer + label printer (`devices`).
- Payment: cash / card / UPI / wallet / split are POS tenders; EMI is not a footwear tender.

Mobile app menu for this trade: Dashboard · POS · Size-wise stock check · Purchase · Customers · Reports

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `garment`, `gst`, `loyalty`, `warranty`, `devices`, so the
workspace needs **Enterprise** for the whole preset to switch on.

- **Starter** — POS, products, inventory, sales, purchase, customers, suppliers, loyalty, **warranty** (deliberately Starter — a single-counter shoe shop takes claims every week).
- **Business** — multi-warehouse, GST, HR, expenses, accounts, service (for the repair bench).
- **Professional** — e-commerce and marketplace channels (Amazon / Flipkart / Meesho / ONDC), advanced analytics.
- **Enterprise** — the size × colour matrix itself — `garment` is Enterprise-only — plus multi-branch and white-label.

## 9. ⬜ Not buildable into the menu yet

- ⚠️ Same pricing gap as Garment: the matrix that makes this trade work is `garment`, which is Enterprise-only.
- Pair-level (left/right) stock, cross-scale size conversion and the repair bench are the three genuine build items.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

