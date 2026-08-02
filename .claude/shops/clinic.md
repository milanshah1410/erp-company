# 🩺 Clinic / Doctor’s Chamber

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `clinic` |
| Vertical | Healthcare |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `gst`, `hrms`, `pharmacy`, `diagnostics` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A consulting room with a small dispensary behind it. The prescription is the document; the consultation and whatever is dispensed against it are both billed on the sales spine.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Patients seen today and consultation revenue
- Prescriptions issued
- Lab orders raised and pending
- Dispensary stock and expiry

## 2. 🩺 Industry menu group — **Clinic**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-heart-fill`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Prescriptions | `/prescriptions` | `pharmacy` (Business) | `prescriptions.view` |
| Lab Orders | `/lab-orders` | `diagnostics` (Business) | `lab-orders.view` |
| Test Master | `/lab-tests` | `diagnostics` (Business) | `lab-tests.view` |
| Drug Register | `/drug-register` | `pharmacy` (Business) | `drug-register.view` |

- A clinic straddles two modules on purpose: `pharmacy` for the dispensary and `diagnostics` for the tests it orders elsewhere.

## 3. 🩺 Clinic / Doctor’s Chamber-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Prescriptions — ✅ `/prescriptions` · `pharmacy`
- Consultation billing — ✅ `/sales-orders` · `sales` — an `is_service` product on the sales spine
- Lab orders — ✅ `/lab-orders` · `diagnostics` — raised, run elsewhere or in-house
- Test master — ✅ `/lab-tests` · `diagnostics`
- Dispensary stock — ✅ `/stock` · `inventory`
  - Batch & expiry — ✅ `/batches` · `inventory`
  - Drug register — ✅ `/drug-register` · `pharmacy`
- Staff — ✅ `/employees` · `hrms`
- Appointment diary — ⬜ declared `planned` (optical has one; clinic does not)
- Patient history / EMR — ⬜ declared `planned`

## 4. Changes to the base

- No POS in the preset — billing follows the consultation.
- HR matters: a clinic is people, not stock.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales` — consultation vs. dispensary
- Receivables ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Doctor-wise / session-wise revenue — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Patient follow-up prediction — ⬜

## 7. 📱 On the counter & on the phone

- Prescription and report printing (`devices`).
- SMS appointment and follow-up reminders.

Mobile app menu for this trade: Prescriptions · Lab orders · Dispensary stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `gst`, `hrms`, `pharmacy`, `diagnostics`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — billing and stock only.
- **Business** — the working tier (`pharmacy` + `diagnostics` + `hrms`).
- **Professional** — not needed.
- **Enterprise** — multi-clinic.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `appointment`
- `patient_history`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

