# 💊 Medical Store / Pharmacy

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `medical` |
| Vertical | Healthcare |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `pharmacy`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The prescription desk and the statutory register are the day's work. Expiry is not a markdown decision here, and a Schedule H1 sale that is not entered is an offence.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Expiry in 30 / 60 / 90 days — the money that dies on the shelf
- Schedule H1 entries pending
- Near-expiry returnable to the distributor
- Today's cash and UPI
- Substitute suggestions taken

## 2. 💊 Industry menu group — **Pharmacy**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-heart-fill`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Prescriptions | `/prescriptions` | `pharmacy` (Business) | `prescriptions.view` |
| Drug Register | `/drug-register` | `pharmacy` (Business) | `drug-register.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Tills | `/pos-counters` | `pos` (Starter) | `pos-counters.view` |
| Devices | `/devices` | `devices` (Starter) | `devices.view` |

- Prescriptions lead the drug register: one is worked all day, the other is written into when a scheduled drug is sold.

## 3. 💊 Medical Store / Pharmacy-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Prescriptions — ✅ `/prescriptions` · `pharmacy` — upload, dispense against it
- Drug register (Schedule H1 / X) — ✅ `/drug-register` · `pharmacy`
- Batch & expiry — ✅ `/batches` · `inventory` — expiry refuses the sale
- Counter POS — ✅ `/pos` · `pos`
  - Tills & cash-up — ✅ `/pos-counters` · `pos`
- Barcode scanner — ✅ `/devices` · `devices`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Distributor expiry return — ✅ `/stock-returns` · `inventory`
- Salt / generic substitute suggestion — ⬜ declared `planned`
- Doctor master & prescription analytics — ⬜
- Narcotic register — ⬜ Schedule H1/X only today

## 4. Changes to the base

- Batch & expiry promoted out of Inventory; loyalty stays (chronic patients repeat).

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory` — the expiry read
- Payables — ✅ `/reports/payables` · `purchase`
- GST summary — ✅ `/reports/gst-summary` · `gst`
- Expiry-due register — ⬜
- Schedule-wise sale register — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead / near-expiry stock — ✅ `/ai-insights` · `ai`
- Substitute recommendation — ⬜

## 7. 📱 On the counter & on the phone

- Barcode scanner and thermal printer (`devices`).
- SMS refill reminders for chronic patients.

Mobile app menu for this trade: POS · Stock / expiry · Purchase · Prescriptions · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `pharmacy`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, batches — no prescription desk or drug register.
- **Business** — the working tier (`pharmacy`), or Starter + **Pharmacy Pack**.
- **Professional** — channels for online medicine orders.
- **Enterprise** — chain of stores.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `substitute`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

