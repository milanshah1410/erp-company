# 📋 Projects / Consulting

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `projects` |
| Vertical | — hand-written preset (no plugin) |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `gst`, `hrms`, `projects` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A consultancy sells hours, not stock. The project board is the day's work, timesheets are the raw material, and billable hours reach the invoice as an `is_service` product.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Projects by status and budget vs. actual
- Unbilled hours
- Timesheets not submitted
- Receivables ageing
- Utilisation by consultant

## 2. 📋 Industry menu group — **Projects**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-briefcase`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Projects | `/projects` | `projects` (Business) | `projects.view` |
| Timesheets | `/project-timesheets` | `projects` (Business) | `project-timesheets.view` |
| Employees | `/employees` | `hrms` (Business) | `employees.view` |
| Attendance | `/attendance` | `hrms` (Business) | `attendance.view` |

- Projects is a top-level section like Service: for a consultancy the board is not a sub-page of something else.

## 3. 📋 Projects / Consulting-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Projects — ✅ `/projects` · `projects` — budget, tasks, status
  - Timesheets — ✅ `/project-timesheets` · `projects`
  - Budget vs. actual costing — on the project record
- Employees / consultants — ✅ `/employees` · `hrms`
  - Attendance — ✅ `/attendance` · `hrms`
  - Payroll — ✅ `/payroll` · `hrms`
- Billing of hours — ✅ `/sales-orders` · `sales` — an `is_service` product
- Quotations / proposals — ✅ `/sales-quotations` · `sales`
- Expenses against a project — ✅ `/expenses` · `accounts`
- Retainer / milestone billing — ⬜ invoices are raised manually against a milestone
- Resource allocation calendar — ⬜

## 4. Changes to the base

- Inventory and POS are irrelevant — drop both groups.
- HR and Projects lead the menu.

## 5. 📈 Reports this trade needs

Base reports plus:

- Profitability — ✅ `/reports/profitability` · `sales` — per project
- Receivables ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Expenses — ✅ `/reports/expenses` · `accounts`
- Utilisation / billable ratio — ⬜
- Budget variance — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Little hardware; email and WhatsApp for client updates.

Mobile app menu for this trade: Projects · Timesheets · Expenses · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `gst`, `hrms`, `projects`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — invoicing and books only.
- **Business** — the working tier (`projects`, `gst`, `hrms`).
- **Professional** — not needed.
- **Enterprise** — multi-entity, API.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

