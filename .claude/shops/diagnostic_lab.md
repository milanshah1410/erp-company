# 🔬 Diagnostic Lab / Pathology

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `diagnostic_lab` |
| Vertical | Healthcare |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `gst`, `diagnostics`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A lab order is a clinical document that happens to be billed — it is raised, collected, run, verified and reported, and the bill is a separate channel (`lab`) on the sales spine.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Worklist: orders awaiting collection, running, awaiting verification
- Reports due today
- Reagent stock and expiry
- Collections and outstanding

## 2. 🔬 Industry menu group — **Diagnostics**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-filter`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Lab Orders | `/lab-orders` | `diagnostics` (Business) | `lab-orders.view` |
| Test Master | `/lab-tests` | `diagnostics` (Business) | `lab-tests.view` |
| Devices | `/devices` | `devices` (Starter) | `devices.view` |

- Orders lead because the worklist is the screen the bench lives on all day; the test master is configured once and rarely touched.
- Reference ranges are snapshotted onto each result, so a later change to the master never rewrites an issued report.

## 3. 🔬 Diagnostic Lab / Pathology-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Lab orders — ✅ `/lab-orders` · `diagnostics` — the worklist
  - Sample collection — a status on the order
  - Result entry — against snapshotted reference ranges
  - Report verification — the doctor's sign-off
- Test master — ✅ `/lab-tests` · `diagnostics`
  - Test panels — a test that contains tests
- Reagent stock — ✅ `/stock` · `inventory`
  - Batch & expiry — ✅ `/batches` · `inventory`
- Billing — ✅ `/sales-orders` · `sales` — channel `lab`
- Devices — ✅ `/devices` · `devices`
- Analyser interface (bidirectional) — ⬜ declared `planned`
- Home collection / phlebotomist round — ⬜ declared `planned`
- Report delivery by WhatsApp / email — partially: the report prints; sending is manual

## 4. Changes to the base

- POS is not the till here — billing follows the order.
- Products are reagents and consumables, not saleable stock.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales` — by channel
- Receivables ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Test-wise volume — ⬜
- Turnaround time — ⬜

## 6. 🤖 AI features

- Reagent purchase suggestion — ✅ `/ai-insights` · `ai`
- Low stock prediction — ✅ `/ai-insights` · `ai`
- Abnormal-result flagging — ⬜ ranges flag, the AI does not

## 7. 📱 On the counter & on the phone

- Report printing (`devices`); analysers are not interfaced.

Mobile app menu for this trade: Worklist · Result entry · Reports due · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `gst`, `diagnostics`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — not workable — no test master, no orders.
- **Business** — the working tier (`diagnostics`), or Starter + **Diagnostic Lab Pack**.
- **Professional** — not needed.
- **Enterprise** — multi-centre with a central lab.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `analyser_interface`
- `home_collection`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

