# 🏪 Retail ERP Base

*The base every shop file builds on. A shop file lists only what it **adds** to this,
and what it **removes** from it.*

Nothing here is trade-specific: a footwear shop, a kirana, a toy shop and a beauty
counter all run this and would all recognise it. Routes are the real ones from
`MenuSeeder`; ⬜ marks something a shop asks for that has no screen yet.

Seeded as `ShopMenuSeeder::common()` — the industry group goes in at **position 3**,
right after Dashboard and Sales.

---

## 1. 🏠 Dashboard

`/` — one screen, widget-configurable (§5 dashboard widgets), so a trade tunes it
without a new route.

| Tile | Source |
|---|---|
| Today's Sales | invoices for today |
| Today's Purchase | vendor invoices for today |
| Cash Summary | `payments.mode = cash` |
| UPI / Card Summary | `payments.mode` split |
| Outstanding (receivable) | customer ledger balance |
| Payable | supplier ledger balance |
| Low Stock | reorder level breach |
| Fast Moving | `/reports/fast-slow` |
| Dead Stock | `/dead-stock` |
| Profit Analysis | `/reports/profitability` |
| Returns today | sales returns |
| Recent Activities | audit log tail |

Trade-specific tiles are listed in each shop file §1.

---

## 2. 💰 Sales

| Screen | Route | Module |
|---|---|---|
| POS Billing | `/pos` | `pos` |
| Quotations | `/sales-quotations` | `sales` |
| Sales Orders / Invoices | `/sales-orders` | `sales` |
| Sales Returns | `/sales-returns` | `sales` |
| B2B Orders (portal approval) | `/b2b-orders` | `sales` |
| Credit Control | `/credit-control` | `distribution` |

- **Credit Note** is not a separate document — a `SalesReturn` *is* the credit note and
  carries `credit_note_number` (prefix configurable in Settings).
- **Delivery Challan** prints off a sales order
  (`/sales-orders/{id}/delivery-challan`); it is a document, not a menu row.
- **Exchange** is not a separate screen — it is a POS action, and it reverses whatever
  the sale granted (points, vouchers, warranty cards, serials). See
  `pos-reversal-paths`.
- Split payment, cash / card / UPI / wallet are POS tenders (`payments.mode` enum).
  A new tender means a migration, not a menu row. EMI as a *file* the shop is asked
  about for a year exists only in the `mobile` vertical (`/mobile/emi`); card EMI is a
  card tender.

## 3. 🛒 Purchase

| Screen | Route |
|---|---|
| Purchase Indents | `/purchase-indents` |
| RFQ / Quotations | `/rfqs` |
| Purchase Orders | `/purchase-orders` |
| Vendor Invoices | `/purchase-invoices` |
| Supplier / Stock Returns | `/stock-returns` |
| Vendor Ratings | `/vendor-ratings` |
| Gate Entries (GRN gate) | `/gate-entries` |
| Brokers · Broker Ledger | `/brokers` · `/brokers/ledger` (`broker`) |

- **GRN** is posted from the purchase document; there is no standalone GRN screen.
- **Purchase Debit Note** ⬜ — raised as a stock return today.

## 4. 🛍️ Product Management

| Screen | Route |
|---|---|
| Products | `/products` |
| Categories (and sub-categories, self-nesting) | `/categories` |
| Brands | `/brands` |
| Units | `/units` |
| Price Lists (incl. quantity slabs) | `/price-lists` |
| Barcode Scan | `/barcode-scan` |
| Label Printing (barcode / QR / sticker / price tag) | `/label-printing` |
| Custom Fields | `/custom-fields` |

- **SKU** is a field on the product, generated per variant by whichever grid owns the
  axis; **sub-categories** are the same `/categories` tree, which self-nests.

- **Variants** are `product_variants`, written by whichever vertical owns the axis
  (garment matrix, cosmetics shades). There is no generic variant screen.
- **Collections / Seasons / Gender / Age Group / Material / Style** are the classic
  ⬜ answer: define them as [custom fields](../dynamic-custom-fields.md) or
  categories. A trade that needs them as first-class registers says so in its file.
- **Combo products** differ by trade on purpose: `grocery` combo packs explode at the
  POS stock pass; `gift` hampers are assembled and move stock at build time.

## 5. 📦 Inventory & 🏬 Warehouse

| Screen | Route |
|---|---|
| Current Stock | `/stock` |
| Stock Ledger | `/stock-ledger` |
| Stock Adjustments (incl. damaged, opening) | `/stock-adjustments` |
| Stock Transfers | `/stock-transfers` |
| Cycle Counts (stock count) | `/cycle-counts` |
| Inventory Alerts | `/inventory-alerts` |
| Reorder | `/reorder` |
| Dead Stock | `/dead-stock` |
| Batches / Lots | `/batches` |
| Serial Numbers | `/serial-numbers` |
| Warehouses | `/warehouses` |

- **Racks / shelves / bins** exist as `warehouse_locations`; the shelf-facing UI is
  the Supermarket module's (`/supermarket/shelves`, `/supermarket/planogram`).
- **Reserved stock** — trials (`garment`) and hire bookings (`sports`) reserve rather
  than move stock. There is no cross-trade reservation screen.
- `StockService` refuses a movement that would take a balance negative, in both
  directions.

## 6. 👥 CRM & Customers

| Screen | Route |
|---|---|
| Customers | `/customers` |
| Customer Categories / Groups | `/customer-categories` |
| Suppliers | `/suppliers` |
| Leads | `/leads` |
| Follow-ups | `/follow-ups` |
| Referrals | `/referrals` |
| Occasions (customer's own dates) | `/crm/occasions` |
| WhatsApp | `/whatsapp` |
| SMS | `/sms` |
| Marketing Campaigns | `/marketing-campaigns` |
| Email Log | `/email-logs` |

- **Feedback / CSAT** ⬜ on the tenant side (CSAT exists for platform support tickets).

## 7. 🎯 Offers & Loyalty — `loyalty`

| Screen | Route |
|---|---|
| Loyalty Points | `/loyalty` |
| Coupons | `/coupons` |
| Gift Vouchers | `/gift-vouchers` |
| Members · Membership Plans | `/memberships` · `/membership-plans` |

- Buy-1-Get-1, combo discount, festival offer, brand discount and membership discount
  are all coupon / offer rules — offers write onto the document *before* pricing.
- A voucher is money already taken: the register reports the outstanding balance as a
  liability.

## 8. 👨‍💼 Accounts, 💸 Expenses & GST

| Screen | Route |
|---|---|
| Vouchers | `/vouchers` |
| Cash Book · Bank Book · Journal · Day Book · Petty Cash | `/accounts/*` |
| Customer / Supplier / Expense Ledger | `/accounts/*-ledger` |
| Trial Balance · P&L · Balance Sheet | `/accounts/*` |
| Bank & Cash Accounts | `/bank-accounts` |
| Expenses · Categories | `/expenses` · `/expense-categories` |
| Employee Claims · Vehicle · Fuel · Electricity | `/employee-claims`, `/vehicle-expenses`, `/fuel-expenses`, `/electricity-bills` |
| Payment Links · Gateways | `/payment-transactions` · `/payment-gateways` |
| GST Registrations · HSN · GST Tools · TDS/TCS | `/gst-registrations`, `/hsn-codes`, `/gst-tools`, `/tds-tcs-rates` |
| E-Invoice · E-Way Bill | `/e-invoices` · `/e-way-bills` |
| Assets · Asset Maintenance | `/assets` · `/asset-maintenances` (`assets`) |

Shop rent, salary, electricity, internet, transport and marketing are **expense
categories**, not menu rows.

## 8b. 👨‍💻 Staff — `hrms`

Departments, Employees, Attendance, Biometric devices & punches, Punch kiosk, Leave
types & applications, Overtime, Salary structures, Payroll, Salary register, Bonuses,
Advances, Loans — `/departments` … `/employee-loans`.

- **Incentives / commission**: salesman targets & incentives (`/salesman-targets`,
  `field_sales`), tailor piece-rate and bonus (`tailoring`). A generic shop-staff sales
  commission is ⬜.

## 8c. 🔔 Notifications

| Item | Where |
|---|---|
| Notification centre | `/notifications` — reached from the **bell in the topbar**, deliberately not a sidebar row (it has no `MenuSeeder` entry) |
| Low stock | `/inventory-alerts` |
| Birthday wishes | `/crm/occasions` + WhatsApp / SMS templates |
| Offer notifications | `/marketing-campaigns` |
| Outstanding reminder | SMS template `sms_tpl_payment_reminder` — sending is manual |
| Daily sales digest | ⬜ no scheduled summary push |

## 9. 📈 Reports

`/reports/sales`, `/reports/purchases`, `/reports/profitability`,
`/reports/stock-overview`, `/reports/stock-movement`, `/reports/fast-slow`,
`/reports/valuation`, `/reports/stock-ageing`, `/reports/receivables-ageing`,
`/reports/payables`, `/reports/expenses`, `/reports/customer-ledger`,
`/reports/supplier-ledger`, `/reports/gst`, `/reports/gst-summary`, `/reports/gstr1`,
`/reports/gstr2`, `/reports/tds-register`, `/reports/tcs-register`.

Every list screen carries View / Filter / Export (CSV) / Print client-side — so
"brand-wise sales" is usually a grouping on an existing report rather than a new one.
Where a trade genuinely needs a new cut (size-wise, shade-wise, ISBN-wise), its file
says ⬜.

## 10. 🔗 Integrations

| Area | Where |
|---|---|
| Barcode scanner · thermal printer · label printer · weighing scale · RFID | `/devices`, `/device-events`, `/printer-test` (`devices`) |
| WhatsApp · SMS (MSG91) · Email | `/whatsapp`, `/sms`, `/email-logs` |
| Razorpay · PhonePe · Paytm · Cashfree | `/payment-gateways` |
| E-commerce & marketplace (7 platforms) | `/ecommerce-integrations`, `/channel-orders`, `/channel-listings`, `/channel-sync-logs` (`ecommerce`) |
| Google address autocomplete | backend-proxied, used inside forms |
| Tally / Busy / Zoho Books export | ⬜ not built |
| Firebase push | ⬜ not built |

## 11. 📱 Mobile app (Flutter) menu

Dashboard · POS Billing · Products · Inventory · Purchase · Sales · Customers ·
Reports · Notifications · Profile. Each shop file names the one or two trade screens
that must also be on the phone.

## 12. 🤖 AI

`/ai-insights` and `/ai-assistant` (`ai`, always on). Local statistical engines —
low-stock prediction, purchase suggestion, demand forecast, dead-stock
recommendation, profit analysis — plus a bilingual chat/voice ERP search. Narration
has a provider seam (local / OpenAI / Anthropic).

## 13. ⚙️ Administration

Users · Roles & Permissions · Companies · Branches · Billing & Plan · Workflows
(`workflow`) · Custom Fields · Settings · System Tools · Audit Logs · Device Manager
(`devices`).

---

## 14. 💡 The four plan levels

Module tiers are `PlanSeeder`'s. Any module can also be bought as a marketplace pack
at a lower tier, so a Business subscriber can add one vertical without paying for
Professional.

| Level | Modules it carries |
|---|---|
| **Starter** | Core (`sales`, `crm`, `purchase`, `inventory`, `accounts`, `ai`) + `pos`, `loyalty`, `assets`, `workflow`, `devices`, `warranty`, `grocery`, `gift`, `toys`, `books` |
| **Business** | + `field_sales`, `broker`, `logistics`, `gst`, `hrms`, `service`, `projects`, `pharmacy`, `jewellery`, `optical`, `restaurant`, `agriculture`, `livestock`, `diagnostics`, `hospital`, `automobile`, `electronics`, `computer_it`, `mobile`, `stationery`, `distribution`, `sports` |
| **Professional** | + `ecommerce`, `manufacturing`, `textile` |
| **Enterprise** | `*` — everything, including `garment`, `supermarket`, `cosmetics`, `tailoring`, `embroidery` |

Multi-branch, franchise, API access and white-label are platform-level (branches,
`limit_overrides`, per-tenant branding), not modules.

> ⚠️ **Three retail verticals are Enterprise-only today** — `garment`, `supermarket`
> and `cosmetics` appear in no tier list and in no marketplace pack. A garment shop,
> a supermarket or a beauty counter therefore cannot buy its own vertical below
> Enterprise. Every other vertical is Business-or-pack. Worth a pricing decision.

---

[← All shops](README.md) · [Factory base](_base-factory.md)
