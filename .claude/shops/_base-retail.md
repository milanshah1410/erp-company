# 🏪 Retail ERP Base

*The base every shop file builds on. A shop file lists only what it **adds** to this,
and what it **removes** from it.*

Nothing here is trade-specific: a footwear shop, a kirana, a toy shop and a beauty
counter all run this and would all recognise it. Routes are the real ones from
`MenuSeeder`; ⬜ marks something a shop asks for that has no screen yet.

Seeded as `ShopMenuSeeder::common()` — the industry group goes in at **position 3**,
right after Dashboard and Sales.

> **A template is the whole menu, not a supplement.** `ShopMenus` clones the
> template *instead of* the catalogue, so any screen this file names and
> `common()` omits is a screen those 19 trades cannot navigate to — even with the
> module switched on. Every route below is therefore on the floor, and each keeps
> the permission and module gate it carries in the catalogue, so a kirana that runs
> no payroll simply never sees §8b.
>
> The resulting order:
>
> `Dashboard · Sales · ‹trade group› · Purchase · Inventory · Products · Barcode &
> Labels · Customers & Suppliers · CRM & Marketing · Offers & Loyalty · Accounts ·
> Expenses & Assets · GST & Tax · Staff & Payroll · Reports · Devices & Channels ·
> AI · Administration`

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
| Customer Satisfaction | CSAT over 30 days — see §6 |
| Recent Activities | audit log tail |

Trade-specific tiles are listed in each shop file §1.

- The CSAT tile shows a **dash, not 0%**, until somebody has answered: a shop with
  no feedback yet is not a shop scoring zero. Its sub-line switches to the
  unanswered-complaint count when there is one, because that is the actionable
  half — a good score with three complaints under it is still three people waiting.
- New widgets are appended to an existing user's layout **hidden**
  (`DashboardController::reconcile`), so nobody's dashboard rearranges itself;
  they switch it on from the widget editor.

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
- **Purchase Debit Note** is not a separate document — a vendor `StockReturn` *is*
  the debit note, exactly as a `SalesReturn` is the credit note. It carries its own
  numbered series (`debit_note_number_prefix`, default `DN`), a claim date, the tax
  rate the original bill charged, and prints at
  `/stock-returns/{id}/debit-note`. Raised on approve authority — it asks another
  business for money — and only against an *approved* vendor return, because
  claiming credit for stock still on the shelf is the one mistake this document can
  make. Filter the register with "Debit notes only"; a vendor return with no claim
  reads as **Not claimed**, which is money left with the supplier. Printed *or*
  emailed to the vendor (`POST /email/debit-notes/{id}`, `email.create`) — it
  rides the same queued document mailer as the invoice, PO and quotation.

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
| Feedback & CSAT | `/customer-feedback` |
| WhatsApp | `/whatsapp` |
| SMS | `/sms` |
| Marketing Campaigns | `/marketing-campaigns` |
| Email Log | `/email-logs` |

- **Feedback / CSAT** is the shop's own score, distinct from the platform's
  support-ticket CSAT. One register, two ways in, and it counts both — a shop whose
  only measured feedback is the link it remembered to send is measuring its own
  diligence:
  - **Ask customers** mints a pending row per recent bill and SMSes
    `sms_tpl_feedback_request`. The customer answers on a public page at
    `/feedback/{token}` — five faces, tag chips, optional comment, no login, one
    answer per link.
  - **Record** writes down what somebody said at the counter.
- Three ways to chase an unanswered ask: SMS again, **WhatsApp** (click-to-chat —
  the server builds the `wa.me` link and logs it to `/whatsapp`, it does not send;
  there is no Business API here), or copy the link.
- A poor rating can raise a notification without anybody opening the screen:
  `customer_feedback.created` is a **workflow trigger** (§11) on `rating`,
  `status` and `channel`. It fires on creation, so it catches counter-recorded
  feedback and not a link answered days later — the digest and the register's
  "needs attention" filter cover that case.
- CSAT is the share of answers rated 4 or 5 (`CustomerFeedback::SATISFIED_FROM`);
  a 3 is neutral and is *not* counted as satisfied. The screen leads with the score,
  then the rating spread (an average of 4.1 made of fives and ones is a different
  shop), then what customers mention, then the book.
- **Acknowledged** and **resolved** are separate states on purpose: "we have read
  this" and "we have dealt with it" are different promises.

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
Advances, Loans, **Sales Commission** — `/departments` … `/sales-commissions`.

- **Incentives / commission** — three schemes, three jobs, and they do not merge:
  | Scheme | Where | Pays |
  |---|---|---|
  | Field rep's monthly target | `/salesman-targets` (`field_sales`) | a rate on achieved sales once the target is met |
  | Tailor piece-rate & bonus | `tailoring` | per operation completed |
  | **Shop-staff commission** | `/sales-commissions` (`hrms`) | a percentage of what the person on the counter rang up |
- Shop-staff commission is line-by-line and **stored**, not derived: rules get
  edited and costs get restated, and a figure somebody has been told they earned
  must not change afterwards. Each earning snapshots its rate and basis.
- Rules are **ranked, not summed**. Several can match one line; the lowest
  `priority` number wins and only that one pays — "1% on everything, 3% on Brand X"
  means three, not four. Both money floors (`min_line_amount`,
  `min_invoice_amount`) are *matching* conditions, so a rule the line does not
  clear is skipped and the next one down pays it.
- Three bases: `percent_of_sale`, `flat_per_unit`, and `percent_of_margin` — the
  last reads cost from the stock ledger (the same source `/reports/profitability`
  uses) and pays **nothing** where no cost was posted, rather than treating the
  gross as profit.
- Who sold it is `sales_orders.created_by` → `employees.user_id`. A bill rung up by
  a user with no employee record is reported, never silently dropped. Returns are
  **netted** off the commissionable quantity rather than clawed back.
- `pending → approved → paid`, plus `reversed`. Re-running a month is safe: earnings
  are keyed on (line, rule, employee), so open rows refresh and paid rows are left
  alone. Reversing is the only way to change a paid figure, and sits on `.approve`.

## 8c. 🔔 Notifications

| Item | Where |
|---|---|
| Notification centre | `/notifications` — reached from the **bell in the topbar**, deliberately not a sidebar row (it has no `MenuSeeder` entry) |
| Low stock | `/inventory-alerts` |
| Birthday wishes | `/crm/occasions` + WhatsApp / SMS templates |
| Offer notifications | `/marketing-campaigns` |
| Outstanding reminder | SMS template `sms_tpl_payment_reminder` — sending is manual |
| Daily sales digest | `sales:daily-digest`, scheduled **21:30**; Settings → Notifications & Daily Digest |

- The digest covers **today**, not yesterday: an Indian shop closes between eight
  and nine, and the message its owner wants is what today came to, read on the way
  home. Bills, average bill, net of returns, collections split by `payments.mode`
  (read from the data, so a new tender appears without editing anything), the day's
  top five items, purchases, new customers, and two worklists for tomorrow — low
  stock and overdue invoices.
- Goes to the bell, to email and to **registered handsets**. Off unless a workspace
  opts in (`sales_digest_enabled`), and silent on a day with no trading — the
  fastest way to train somebody to ignore a channel is to use it to say nothing.
- Recipients are admins, accountants and sales managers only: it is a whole-shop
  figure set, not a counter hand's business. "Send me today's summary now" on the
  Settings card goes to the caller alone.

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
| Tally / Busy / Zoho Books export | `/accounting-exports` (`accounts`) |
| Firebase push | Settings → Notifications & Daily Digest; `/push-devices` |

- **Accounting export** is a deliberate one-way bridge: the shop keeps its books
  here and its chartered accountant keeps Tally, so this writes a file they import.
  Pulling balances back would put two systems in charge of the same numbers.
  - **Tally** takes one XML envelope with masters *and* vouchers together — masters
    always lead, because Tally rejects a voucher citing a ledger it has not seen.
    Sales, purchase, receipt, payment, journal and expense vouchers, with Tally's
    own sign convention (`ISDEEMEDPOSITIVE` Yes + a negative amount is a debit).
  - **Busy** and **Zoho Books** take one CSV per document type, so they refuse
    `all` and say what to pick instead. Contacts first — Zoho matches by name.
  - Each expense **category** becomes its own ledger, so "Shop Rent" and
    "Electricity" arrive as ledgers rather than one lump.
  - Manual vouchers with only one ledger filled in are legitimate here but are not
    a journal entry any package accepts. They are **counted and reported** on the
    screen, never silently dropped.
  - Its own permission (`accounting-exports.view`, read-only) rather than
    `reports.view`: the file carries every party and every ledger name the workspace
    holds, in one download that leaves the building.
- **Firebase push** follows the SMS/mail driver seam — `log` by default, so device
  registration, the `push` notification channel and the settings screen all work
  with no Firebase project. `fcm` sends over **HTTP v1** (service-account JSON → a
  signed JWT → a cached OAuth token); the legacy server key is not offered because
  Google shut that endpoint down in 2024. Any notification opts in by listing
  `'push'` in `via()` and providing `toPush()`. A token FCM reports as
  `UNREGISTERED` is switched off, not deleted, so a dead handset stays visible.
  The mobile app re-registers on every start — FCM rotates tokens.

## 11. 📱 Mobile app (Flutter) menu

Dashboard · POS Billing · Products · Inventory · Purchase · Sales · Customers ·
Reports · Notifications · Profile. Each shop file names the one or two trade screens
that must also be on the phone.

- **Notifications** now reach the handset, not just the in-app list: the app posts
  its FCM token to `POST /api/push-devices` on every start (tokens rotate), and
  anything with a `toPush()` — the daily digest today — arrives on the lock screen.
  No permission gate: it is the phone in the caller's own pocket.

## 12. 🤖 AI

`/ai-insights` and `/ai-assistant` (`ai`, always on). Local statistical engines —
low-stock prediction, purchase suggestion, demand forecast, dead-stock
recommendation, profit analysis — plus a bilingual chat/voice ERP search. Narration
has a provider seam (local / OpenAI / Anthropic).

## 13. ⚙️ Administration

Users · Roles & Permissions · Companies · Branches · Billing & Plan · Workflows
(`workflow`) · Custom Fields · Settings · System Tools · Audit Logs · Device Manager
(`devices`).

- **Settings → Notifications & Daily Digest** holds the digest opt-in, the Firebase
  push driver and "your registered phones". Every SMS template the backend can send
  is editable under **SMS Gateway** — a template the app sends but the screen does
  not list is wording a shop cannot change.
- **Demo data** (System Tools) covers the new screens: `customer_feedback` produces
  a *spread* of ratings including complaints — a demo whose CSAT reads 100% shows
  nothing about a screen whose point is the complaints worklist — and
  `commission_rules` / `commission_earnings` run the **real** engine over three
  months, so the figures on screen agree with the rules beside them. Earnings are
  generated after `employees` for that reason; picking them alone yields nothing,
  and says so.

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
