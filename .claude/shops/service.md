# 🔧 Service / Repair Centre

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `service` |
| Vertical | — hand-written preset (no plugin) |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `gst`, `hrms`, `service`, `projects` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The generic repair centre — the trade a workshop signs up as when its vertical (automobile, electronics, computer) is not the point. Job intake, technicians, spare parts, AMC contracts and billing.

Labour bills through the sales spine as an `is_service` product; there is no separate labour ledger.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Open job cards by stage and age
- Jobs awaiting parts / awaiting approval
- AMC contracts expiring in 30 days
- Technician-wise load and closures
- Warranty vs. paid work

## 2. 🔧 Industry menu group — **Service**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-wrench`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| AMC Contracts | `/service-contracts` | `service` (Business) | `service-contracts.view` |
| Projects | `/projects` | `projects` (Business) | `projects.view` |
| Timesheets | `/project-timesheets` | `projects` (Business) | `project-timesheets.view` |
| Employees | `/employees` | `hrms` (Business) | `employees.view` |

- Service is a top-level section in the catalogue rather than a leaf under Sales: for a repair centre this *is* the day's work.

## 3. 🔧 Service / Repair Centre-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Service jobs — ✅ `/service-jobs` · `service` — intake, diagnosis, parts, labour, closure
- AMC contracts — ✅ `/service-contracts` · `service`
- Spare parts — ✅ `/products`
  - Parts issue against a job — on the job card
- Technicians — ✅ `/employees` · `hrms`
  - Attendance — ✅ `/attendance` · `hrms`
- Projects — ✅ `/projects` · `projects` — for longer site work
  - Timesheets — ✅ `/project-timesheets` · `projects`
- Estimates / approval — quotations
- Warranty cards — ✅ `/warranties` · `warranty` — only if `warranty` is switched on
- Counter POS — ✅ `/pos` · `pos` — over-the-counter parts and small jobs
- Job-card SLA / turnaround target — ⬜
- Loaner / standby unit tracking — ⬜

## 4. Changes to the base

- Service is promoted above Sales; Inventory is parts, not saleable stock.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales` — labour vs. parts
- Profitability — ✅ `/reports/profitability` · `sales`
- Receivables ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Technician productivity — ⬜
- Job turnaround — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Job-card printing (`devices`).
- SMS / WhatsApp on job completion.

Mobile app menu for this trade: Job cards · AMC · Parts stock · Timesheets · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `gst`, `hrms`, `service`, `projects`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — billing, stock, accounts — no job cards.
- **Business** — the working tier (`service`, `projects`, `gst`, `hrms`).
- **Professional** — not needed unless the centre also manufactures.
- **Enterprise** — multi-branch service networks.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

