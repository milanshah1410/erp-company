# 🏭 Factory / Back-office Base

*The base for trades that have no shop counter: the eleven Manufacturing trades, farms,
consultancies, construction and import/export.*

Same ERP, different floor. It is the [Retail base](_base-retail.md) **minus the counter**
and **plus production**, so a factory does not open its day looking at a POS tile.

> **Shipped.** `ShopMenuSeeder::factoryCommon()` is this floor, and
> `factorySections()` holds the eleven trades that sit on it. A trade is on the
> factory floor exactly when it appears in `factorySections()` — the same place its
> production group is declared — so a works can never end up with a shop-floor menu
> group on top of a POS-led floor.
>
> **A template is the whole menu, not a supplement.** `ShopMenus` clones the
> template *instead of* the catalogue, so any screen this file names and the
> template omits is a screen that trade cannot navigate to at all. Every route
> below is therefore on the floor — §5–§9 included, not just the production
> groups — and each keeps the permission and module gate it carries in the
> catalogue, so naming a Professional screen costs a Business workspace nothing.
>
> ⚠️ The opening line above also claims farms, consultancies, construction and
> import/export. Their own files (`dairy_farm`, `poultry`, `projects`,
> `construction`, `trading`) each declare the **Retail** base instead, so they are
> deliberately *not* implemented here — one of the two statements is wrong and it
> is not this file's place to decide which. Worth settling before those five get
> templates.

---

## Removed from the retail floor

| Removed | Why |
|---|---|
| POS Billing (`/pos`) | nobody walks in; the order arrives as a PO |
| Offers & Loyalty (`loyalty`) | no consumer to reward |
| Barcode & Labels as a top group | still available under Products; not a daily group |
| Occasions, Referrals | consumer CRM |
| Marketing Campaigns | consumer marketing; WhatsApp and SMS are kept, a mailing list is not |

Kept and promoted: Purchase leads Sales, Inventory is a top-level group, HR & Payroll is
not optional (piece-rate and shift labour are a cost of goods, not an admin chore).

The resulting order — the same for all eleven trades, with the trade's own group
spliced in **third**:

`Dashboard · Purchase · ‹trade group› · Sales · Inventory · Products · Customers &
Suppliers · CRM · People & Payroll · Logistics · Accounts · Expenses & Assets ·
GST & Tax · Reports · AI · Administration`

Nine trades splice in one group. Two splice in **two**, and both earn it: the
general `manufacturing` preset keeps §2 and §3 apart as Production and Shop Floor
(a planner's screen and a supervisor's are different jobs in a works big enough to
employ both), and `garment_manufacturing` runs three chains at once and splits them
into Garment Unit and Tailoring & Embroidery (§4). Everything after the splice is
identical across all eleven.

Expenses & Assets carries the factory heads the base names below — plant
maintenance, power and fuel — and Reports leads with `/reports/manufacturing` and
`/reports/production-cost`, because what a finished good cost to make is the
question a works opens the group to answer.

## 1. 🏠 Dashboard

| Tile | Widget key | Reads |
|---|---|---|
| Work in Progress | `production_wip` | MOs `in_progress` — count, units, cost committed |
| Today's Production | `production_today` | `quantity_produced` on MOs completed today |
| Machines Down | `machine_downtime` | machines at `breakdown`/`maintenance` now, + hours lost this month |
| QC Rejection | `qc_rejection` | rejected ÷ (accepted + rejected), this month |
| Scrap Cost | `scrap_value` | scrap value net of recoverable, this month |
| Job Work at Vendors | `job_work_outstanding` | open job works, and how many are past the promised date |
| Orders in Hand | `orders_in_hand` | open sales orders — count and value |
| Receivables | `receivables` | unpaid + partial invoices, and the overdue slice |
| Payables | `payables` | unpaid + partial **vendor** bills, and the overdue slice |

- They live in the **shared** widget catalogue (`DashboardController::WIDGETS`),
  not behind a separate factory dashboard: the layout is already per-user, and
  `reconcile()` appends anything new **hidden** — so a kirana never grows nine
  tiles it did not ask for and a works switches them on once. A second dashboard
  endpoint would have been a second thing to keep in step.
- **Machines Down leads with the count that is still actionable today** ("3/23"),
  not the month's lost hours, which are already spent.
- **QC Rejection shows a dash, not 0%**, when nothing was inspected. A works that
  ran no inspections has no rejection rate, and a green 0% would read as a perfect
  month.
- **Payables reads `payment_status`, not `status`.** On `purchase_invoices` those
  are different columns — `status` is the *approval* state — and reading the wrong
  one totals every unapproved bill as a debt.
- **Raw-material shortfall against open orders** is deliberately *not* a tile — it
  is a report, at **`/ai-insights` → Material Shortfall** (§10). Doing it honestly
  means exploding every open order's BOM with wastage and netting against stock
  *and* against what is already on order: far too slow for a five-minute-cached
  dashboard, and a shortfall that quietly ignored open POs would send a factory out
  to buy material it had already bought.

## 2. 🏭 Production — `manufacturing` *(Professional, or Manufacturing Pack)*

| Screen | Route |
|---|---|
| Bills of Materials | `/boms` |
| Manufacturing Orders | `/manufacturing-orders` |
| Job Work (outsourced) | `/job-works` |
| Colour Printing | `/colour-printings` |
| Recipes (food producers) | `/restaurant/recipes` |

## 3. 🔧 Shop Floor — `manufacturing`

| Screen | Route |
|---|---|
| Machines | `/machines` |
| Machine Utilisation | `/machines/utilisation` |
| Quality Inspection | `/quality-inspections` |
| Scrap & Rejection | `/production-scraps` |

- Scrap posting depends on what was scrapped: **material** scrap returns to / writes off
  raw stock, **finished** scrap writes off the finished good. Costing spans eight files —
  see [manufacturing-shop-floor](../../memory/manufacturing-shop-floor.md) if extending it.

## 4. 🧵 Trade chains

| Chain | Module | Screens | Carried by |
|---|---|---|---|
| Textile | `textile` (Professional) | `/fabric-rolls`, `/fabric-inspections`, `/shrinkage-records`, `/fabric-cuttings` | `textile_mill` (all four), `garment_manufacturing` (rolls + cutting) |
| Tailoring | `tailoring` (Enterprise / pack) | `/tailors`, `/contractors`, `/rate-cards`, `/piece-rates`, `/tailor-works/pending`, `/tailor-works/completed`, `/tailor-alterations`, `/tailor-bonuses`, `/tailor-performance`, `/tailor-payments`, `/tailors/ledger` | `garment_manufacturing`, complete |
| Embroidery | `embroidery` (Enterprise / pack) | `/embroidery-vendors`, `/embroidery-works/pending`, `/embroidery-works/received`, `/embroidery-payments`, `/embroidery-vendors/ledger` | `embroidery_unit` and `garment_manufacturing`, both complete |

- A chain is listed **whole or not at all**. Rate cards and piece rates are the
  tailoring chain's *masters*, not extras — a unit that can issue tailor work but
  cannot define what a rate is has a chain with its first link missing. Same on the
  embroidery side: dispatch without receipt and payment is a book that only opens.
- `garment_manufacturing` runs three chains at once, so it is the one trade with
  **two** industry groups — Garment Unit (the order and the cloth) and Tailoring &
  Embroidery (everyone the work goes out to). One heading of twenty-three rows is
  not a menu.
- The chains appear only on the trades that run them. That is per-trade
  specialisation, not an omission: a moulding shop has no fabric rolls, and each
  trade's own file is the spec for its group.

## 5. 📦 Materials

Purchase (indents → RFQ → PO → vendor invoice → gate entry), Inventory (stock, ledger,
adjustments, transfers, batches, serials, cycle counts), Warehouses. Same routes as the
retail base.

## 6. 🚚 Logistics — `logistics`

Vehicles, Drivers, Delivery Routes, Dispatch Planning, Proof of Delivery, Transport
Charges — **and** the import/export desk on the same module: `/import-export`, the
active import and export shipment boards, Timeline, ETA/ETD, Pending Documents,
Pending Customs, Pending Payments, Container Status, Shipment Profit and Logistics
Expenses.

Their **masters** — Countries, Ports, Shipping Lines, Airlines, Freight Forwarders,
CHA Agents, Transporters, Overseas Suppliers and Overseas Customers — sit in the
floor's *Customers & Suppliers* group rather than a Masters heading of their own. A
works that exports has foreign buyers and customs agents in the same book as its
domestic accounts, and splitting them across two headings is a distinction only the
software cares about.

## 7. 👨‍💻 People — `hrms`

Not optional here, and carried whole: Departments, Employees, Attendance, Biometric
Devices, Biometric Punches, Punch Kiosk, Leave Types, Leave Applications, Overtime,
Salary Structures, Payroll, Salary Register, Bonuses, Advances, Loans.

A works that runs shifts settles advances and loans against them every month —
leaving those in a back office the menu never opens is how they get settled on
paper instead. Piece-rate workers are `tailoring`'s rate cards; contract labour is
`/contractors`, which rides with that chain (§4).

## 8. 💰 Finance, GST, Assets

The retail base's finance floor entire — vouchers, day book, the three party
ledgers, cash / bank / journal / petty cash, trial balance, P&L, balance sheet, bank
accounts, payment links and gateways, and the Tally / Busy / Zoho export — plus:

- `assets`: the plant & machinery register and its maintenance schedule, which a
  factory always runs;
- the factory expense heads `/factory-maintenances`, `/electricity-bills`,
  `/fuel-expenses`, alongside the shared `/employee-claims` and `/vehicle-expenses`;
- GST with `/tds-tcs-rates`, e-invoice and e-way bill.

## 9. 📈 Reports

`/reports/manufacturing`, `/reports/production-cost`, `/reports/printing`,
`/reports/tailor`, `/reports/embroidery`, plus the shared purchase / inventory /
finance / GST reports (`/reports/purchases`, `/reports/stock-overview`,
`/reports/stock-movement`, `/reports/valuation`, `/reports/stock-ageing`,
`/reports/sales`, `/reports/profitability`, `/reports/expenses`,
`/reports/payables`, `/reports/receivables-ageing`, `/reports/gstr1`,
`/reports/gst-summary`).

The three chain reports are listed for **every** trade, not only the ones that
obviously run them: each carries its own module gate, so a moulding shop never sees
them, and a garment unit that adds embroidery next season does not need its menu
re-cut.

## 10. 🤖 AI

Same engines, different questions. Three tabs on `/ai-insights`, shown only where
the `manufacturing` module is on — the endpoints carry the same gate, so a kirana
gets a 403 rather than an empty panel that looks like an answer.

| Tab | Endpoint | Asks |
|---|---|---|
| Material Shortfall | `/ai/material-shortfall` | what are we short of for the orders already on the floor |
| Machine Downtime | `/ai/machine-downtime` | which machine keeps stopping, and why |
| Scrap Outliers | `/ai/scrap-outliers` | what is scrapping unlike everything else |

All local arithmetic (`ShopFloorIntelligenceService`), no hosted model — which
matters when the answer is "go and buy 400 kg of resin". Four decisions worth
keeping in view:

- **Shortfall nets against open purchase orders**, not just stock. Ignoring what
  is already on order is the one mistake that would send a buyer out to re-buy
  what is coming — and it uses the *same* statuses as
  `InventoryIntelligenceService::onOrder()`, so the two engines can never give one
  buyer two answers on the same afternoon. It also scales by what is still to
  produce (ordered − produced), applies each BOM line's wastage, and skips MOs
  whose material has already been issued.
- **Orders with no BOM cannot be exploded**, so they are counted and reported on
  the panel. A works with half its orders un-BOM'd should be told, not shown a
  reassuring short list.
- **Availability is measured against scheduled hours**, with the shift length
  adjustable on the panel. A press down overnight when nobody is running it is not
  a broken press, and a works on three shifts must be able to say so.
- **Scrap is compared against the median, not the mean** — one catastrophic batch
  would drag an average upward and hide every genuine outlier behind it. Articles
  with scrap but *no finished output yet* are excluded rather than shown at 100%:
  scrap is booked while an order is still running, and an alarm that fires on
  healthy orders is one a works learns to ignore.

Dead raw stock stays where it already was — `/dead-stock`, which is not
raw-material-specific and does not need to be.

## 11. 💡 Plan levels

`manufacturing` and `textile` are **Professional**; `tailoring` and `embroidery` are
**Enterprise**, or all four together via the **Textile & Garment Pack**. A factory on
Business gets purchase, inventory, accounts, GST, HR and logistics but no production —
which is why the **Manufacturing Pack** exists for that tier.

Verified against `PlanSeeder` and `PlatformPackSeeder`: Starter and Business carry
none of the four, Professional carries `manufacturing` + `textile`, Enterprise all
four. Both packs are live and priced. A pack's `included_in_plans` means *bundled
free at that tier*, not *only sellable to it* (`PlatformPack::isBundledWith`) — so a
Business factory is not bundled the Manufacturing Pack and can buy it, which is
exactly what the paragraph above claims.

---

[← All shops](README.md) · [Retail base](_base-retail.md)
