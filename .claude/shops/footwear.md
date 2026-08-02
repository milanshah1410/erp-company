# 👟 Footwear Shop

*ERP menu requirement. **[Retail ERP base](_base-retail.md) + footwear-specific modules.***

| | |
|---|---|
| Trade key | `footwear` |
| Vertical | Retail (`RetailPlugin`) |
| Base | [Retail ERP base](_base-retail.md) — read that first |
| Industry modules | `pos`, `garment`, `gst`, `loyalty`, `warranty`, `devices` |
| Minimum plan | **Enterprise** for the whole preset (see §6 — `garment` is the blocker) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()['footwear']` |

The trade shares the garment size × colour grid — a shoe is a style in eleven sizes and four
colours — and adds the two things a shoe shop lives on that a garment shop does not: a warranty
the customer walks back in with, and exchange as a routine rather than an exception.

> **This file lists only what is footwear.** CRM, Product Management, Inventory, Warehouse,
> Purchase, Sales, Payments, Offers, Accounts, Expenses, Staff, Notifications, Settings,
> Integrations and the shared reports are all in [the base](_base-retail.md) and are not repeated
> here. §7 below is the audit trail: every line of the trade's own spec, and where it is answered.

**Legend** — ✅ a screen exists · 🟡 exists, but not as its own menu row (a document, a POS
action, a print, an attribute) · ⬜ nothing to point at; build or custom field.

---

## 1. 🏠 Dashboard — extra tiles

The [base tiles](_base-retail.md) plus these four, which are what a shoe shop
actually opens the day on:

| Tile | Status | Note |
|---|---|---|
| **Size-wise sales** | ⬜ | *the* footwear number; the reorder is made off it |
| **Broken size runs** | ⬜ | a style with the middle sizes gone is dead stock at full price |
| **Exchanges today (net)** | ⬜ | visible as POS documents, not as a tile |
| **Open warranty claims** | ✅ | count off `/warranties/claims` |

## 2. 👟 Industry menu group — **Footwear**

Icon `pi pi-directions`, inserted **third** in the sidebar — after Dashboard and Sales.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Size Runs | `/garment/size-sets` | `garment` (Enterprise) | `garment-styles.view` |
| Size & Colour Matrix | `/garment/matrix` | `garment` (Enterprise) | `products.view` |
| Colours | `/garment/colours` | `garment` (Enterprise) | `garment-styles.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Warranty Claims | `/warranties/claims` | `warranty` (Starter) | `warranty-claims.view` |

- The size × colour grid is `garment`'s and is reused verbatim — a second grid for shoes would be
  the same table under a different name.
- Warranty leads the claims book: the register is what gets looked up ("is this covered?"); the
  claim is only opened once the answer is yes.

## 3. 👞 Footwear-specific detail

- **Size Management** — ✅ `/garment/size-sets`
  - EU / UK / US / Kids scales — 🟡 one size run per scale today
  - Cross-scale conversion (UK 8 = EU 42) — ⬜ **build**: no conversion table
- **Colour Management** — ✅ `/garment/colours` (black, white, brown, blue, multi…)
- **Size × Colour Matrix** — ✅ `/garment/matrix` — one grid, writes plain `product_variants`
- **Pair Management** — ⬜ **build**
  - Left / right pair, single-pair availability: stock is per article, not per foot
  - The trade's one genuinely missing data structure
- **Brand Collections** — ✅ `/brands` (Nike, Adidas, Puma, Bata, Woodland, local)
  - A collection *within* a brand — ⬜ custom field
- **Style** (casual, formal, sports, running, slippers, sandals, loafers, sneakers, boots) — ⬜ category tree or custom field
- **Gender** (men / women / kids / unisex) — ⬜ custom field
- **Material** (leather, PU, canvas, rubber, foam) — ⬜ custom field
- **Warranty** — ✅
  - Warranty period — on the card at `/warranties`; a card is issued against the sale
  - Warranty claim — ✅ `/warranties/claims`
  - Replacement — 🟡 a claim outcome, settled as a POS exchange
- **Exchange Module** — 🟡 a POS action, not a screen
  - Size exchange · colour exchange · product exchange — one flow, three reasons
  - It reverses everything the sale granted (points, voucher redemption, the warranty card), and
    both `processReturn` **and** `exchange` must undo it — which is why it stays inside POS
- **Repair (optional)** — ⬜ sole change, stitching, polish would be `service` job cards, and
  `service` is **not** in the footwear preset

> Style, Gender, Age Group, Material and Collections are one decision, not five: define them as
> [dynamic custom fields](../dynamic-custom-fields.md) on the product and they become filterable
> and reportable with no schema change. Promote one to a real register only if the shop needs to
> *transact* against it.

## 4. Changes to the base

- **Warranty is promoted** out of the base into the industry group — the claims desk is a daily
  queue for this trade, not an occasional one.
- **Sales**: keep POS first; exchange is the busiest action at this counter.
- **Products**: the matrix becomes the way into stock; `/products` is the fallback, not the front door.
- Nothing is removed from the base.

## 5. Footwear-specific reports & AI

Everything else is in [the base](_base-retail.md).

| Wanted | Status | Note |
|---|---|---|
| Brand-wise sales | 🟡 | `/reports/sales` grouped by brand in the filter bar |
| Category-wise sales | 🟡 | same, grouped by category |
| **Size-wise sales** | ⬜ | **build** — the trade's most-asked report; the data is on `product_variants` and nothing reads it that way |
| **Colour-wise sales** | ⬜ | **build** |
| **Exchange register** | ⬜ | exchanges are POS documents with no report over them |
| **Best Selling Size** (AI) | ⬜ | blocked on the size-wise cut above |
| **Best Selling Brand** (AI) | 🟡 | readable from sales grouped by brand |
| **Seasonal Demand Prediction** (AI) | ⬜ | the forecast engine is not season-aware |

## 6. 💡 Plan levels

Your four levels against what the tiers actually carry (`PlanSeeder`):

| Your level | You listed | Reality |
|---|---|---|
| **1 Starter** | Dashboard, POS, Products, Inventory, Sales, Purchase, Customers, Suppliers | ✅ all Starter — **and more**: Loyalty, Barcode, Warranty, Assets, Workflow and Devices are Starter too, though you had them at Level 2 |
| **2 Business** | Multi Warehouse, Loyalty, Exchange, Barcode, Reports, Expenses, Accounts | 🟡 every one of these is **already Starter** (multi-warehouse is core inventory; exchange is a POS action). What Business actually adds for this trade is **GST**, **HR & Payroll** and **Service** (the repair bench) |
| **3 Professional** | Staff Management, Commission, AI Reports, WhatsApp, Mobile App, Advanced Analytics | 🟡 Staff is Business (`hrms`); WhatsApp, AI and analytics are **Starter**. Professional's real content is **`ecommerce`**, `manufacturing` and `textile` |
| **4 Enterprise** | Multi Branch, Franchise, E-commerce, Marketplace, API, White Label | 🟡 e-commerce and marketplace are **Professional**. Multi-branch is `/branches` (core), white-label is per-tenant branding, API and franchise are platform-level. What is genuinely Enterprise-only is **`garment`** — the size × colour matrix |

**The decision this raises:** the module that makes the trade work — `garment` — sits in **no plan
tier and no marketplace pack**, so it is Enterprise-only, while a single-counter footwear shop is a
Starter/Business customer. Either move `garment` into Business, or ship a **Garment & Footwear
Pack** in the marketplace.

## 7. Audit — the rest of the trade spec

Everything else asked for is generic retail and is answered in the base. Listed once here so
nothing in the spec goes unanswered; **not** repeated as menus.

| Asked for | Answer | Where |
|---|---|---|
| Dashboard (sales, purchase, cash, UPI, outstanding, low stock, fast moving, dead stock, profit, returns, activities) | ✅ | [base §1](_base-retail.md) |
| CRM (leads, customers, groups, loyalty members, gift voucher, follow-ups) | ✅ | [base §6](_base-retail.md) · [§7](_base-retail.md) |
| **Feedback** | ⬜ | no tenant-side feedback capture — CSAT exists only for platform support tickets |
| Products, categories & sub-categories, brands, variants, SKU, barcode, QR, price list, discount rules | ✅ | [base §4](_base-retail.md) |
| **Combo Products** | ⬜ | combos exist in `grocery` (explode at POS) and `gift` (assembled) — neither module is in this preset |
| Inventory (overview, adjustment, transfer, count, opening, damaged, low stock, dead stock, batch) | ✅ | [base §5](_base-retail.md) — opening & damaged are adjustment reasons |
| **Reserved Stock** | ⬜ | garment trials reserve stock; footwear has no reservation screen |
| Warehouses & transfer | ✅ | [base §5](_base-retail.md) |
| **Racks / Shelves / Bin** | 🟡 | `warehouse_locations` exists; the only UI over it is `supermarket`'s, not in this preset |
| Purchase (PO, invoice, GRN, supplier return, broker) | ✅ | [base §3](_base-retail.md) — GRN posts from the purchase document |
| **Purchase Debit Note** | ⬜ | a supplier return posts the debit; no separate note document |
| Sales (POS, invoice, return, **credit note**, **delivery challan**, quotations) | ✅ | [base §2](_base-retail.md) — a `SalesReturn` *is* the credit note (`credit_note_number`); the challan prints off a sales order |
| Payments (cash, card, UPI, wallet, split) | ✅ | POS tenders, the `payments.mode` enum — [base §2](_base-retail.md) |
| **EMI** | ⬜ | EMI files exist only in the `mobile` vertical; card EMI is a card tender |
| Offers (coupon, festival, brand, membership discount) | ✅ | [base §7](_base-retail.md) |
| **Buy 1 Get 1 / Combo Discount** | ⬜ | no quantity-based free-goods rule |
| Accounts (cash book, bank book, journal, ledger, GST, P&L) & Expenses | ✅ | [base §8](_base-retail.md) — expense heads are categories |
| Staff (employees, attendance, salary) | ✅ | [base §8b](_base-retail.md) — `hrms` is not in this preset; tick it in the wizard |
| **Incentives / Commission** (shop staff, per sale) | ⬜ | `/salesman-targets` is `field_sales` (beat-based). Per-staff commission needs a salesperson tag on the POS bill |
| Notifications (low stock, birthday, offers) | ✅ | [base §8c](_base-retail.md) — the centre is `/notifications`, reached from the topbar bell, **not** a sidebar row |
| **Daily sales digest** | ⬜ | no scheduled summary push |
| Settings (company, GST, barcode, printer, payment methods, users, roles, permissions) | ✅ | [base §13](_base-retail.md) |
| Integrations (scanner, thermal & label printer, WhatsApp, Razorpay / PhonePe / Cashfree, marketplaces) | ✅ | [base §10](_base-retail.md) |
| **Tally / Busy export · Firebase push** | ⬜ | not built |
| Flutter mobile menu | ✅ | [base §11](_base-retail.md) — plus **size-wise stock check** and **warranty lookup** for this trade |
| AI (low stock, purchase suggestion, dead stock, buying pattern, sales summary) | ✅ | [base §12](_base-retail.md) |

## 8. ⬜ Build backlog

Ranked by how often a shop will ask:

1. **Size-wise & colour-wise sales report** — the reorder decision depends on it.
2. **`garment` pricing** (§6) — a commercial decision, not a build.
3. **Pair management** (left/right, single-pair availability) — the only missing data structure.
4. **Repair bench** — add `service` to the preset; sole change / stitching / polish are job cards.
5. **Cross-scale size conversion** (UK ↔ EU ↔ US).
6. **Buy-1-Get-1** offer rule, and combo pricing.
7. **Shop-staff sales commission** — needs a salesperson tag on the POS bill.
8. Cross-trade: feedback capture, Tally / Busy export, Firebase push, daily-sales digest.

The Retail plugin marks every footwear feature `available`, so none of the gaps above is a
plugin-level `planned` flag — they are core build items.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)
