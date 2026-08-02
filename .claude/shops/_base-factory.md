# 🏭 Factory / Back-office Base

*The base for trades that have no shop counter: the eleven Manufacturing trades, farms,
consultancies, construction and import/export.*

Same ERP, different floor. It is the [Retail base](_base-retail.md) **minus the counter**
and **plus production**, so a factory does not open its day looking at a POS tile.

---

## Removed from the retail floor

| Removed | Why |
|---|---|
| POS Billing (`/pos`) | nobody walks in; the order arrives as a PO |
| Offers & Loyalty (`loyalty`) | no consumer to reward |
| Barcode & Labels as a top group | still available under Products; not a daily group |
| Occasions, Referrals | consumer CRM |

Kept and promoted: Purchase leads Sales, Inventory is a top-level group, HR & Payroll is
not optional (piece-rate and shift labour are a cost of goods, not an admin chore).

## 1. 🏠 Dashboard

Orders in hand · Work in progress · Today's production · Machine downtime · QC
rejection % · Scrap value · Raw-material shortfall against open orders · Job work
outstanding with vendors · Receivables · Payables.

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

| Chain | Module | Screens |
|---|---|---|
| Textile | `textile` (Professional) | `/fabric-rolls`, `/fabric-inspections`, `/shrinkage-records`, `/fabric-cuttings` |
| Tailoring | `tailoring` (Enterprise / pack) | `/tailors`, `/contractors`, `/rate-cards`, `/piece-rates`, `/tailor-works/pending`, `/tailor-works/completed`, `/tailor-alterations`, `/tailor-bonuses`, `/tailor-performance`, `/tailor-payments`, `/tailors/ledger` |
| Embroidery | `embroidery` (Enterprise / pack) | `/embroidery-vendors`, `/embroidery-works/pending`, `/embroidery-works/received`, `/embroidery-payments`, `/embroidery-vendors/ledger` |

## 5. 📦 Materials

Purchase (indents → RFQ → PO → vendor invoice → gate entry), Inventory (stock, ledger,
adjustments, transfers, batches, serials, cycle counts), Warehouses. Same routes as the
retail base.

## 6. 🚚 Logistics — `logistics`

Vehicles, Drivers, Delivery Routes, Dispatch Planning, Proof of Delivery, Transport
Charges. Import & Export shipments and their masters sit under the same module —
`/import-export/*` and Masters → Import & Export.

## 7. 👨‍💻 People — `hrms`

Not optional here. Departments, Employees, Attendance, biometric punches, Leave,
Overtime, Salary structures, Payroll, Bonuses, Advances, Loans. Piece-rate workers are
`tailoring`'s rate cards; contract labour is `/contractors`.

## 8. 💰 Finance, GST, Assets

As the retail base, plus `assets` (plant & machinery register with maintenance
schedules) which a factory always runs, and factory-specific expense heads
(`/factory-maintenances`, `/electricity-bills`, `/fuel-expenses`).

## 9. 📈 Reports

`/reports/manufacturing`, `/reports/production-cost`, `/reports/printing`,
`/reports/tailor`, `/reports/embroidery`, plus the shared purchase / inventory /
finance / GST reports.

## 10. 🤖 AI

Same engines, different questions: raw-material purchase suggestion against open
orders, machine downtime patterns, scrap-rate outliers, dead raw stock.

## 11. 💡 Plan levels

`manufacturing` and `textile` are **Professional**; `tailoring` and `embroidery` are
**Enterprise**, or all four together via the **Textile & Garment Pack**. A factory on
Business gets purchase, inventory, accounts, GST, HR and logistics but no production —
which is why the **Manufacturing Pack** exists for that tier.

---

[← All shops](README.md) · [Retail base](_base-retail.md)
