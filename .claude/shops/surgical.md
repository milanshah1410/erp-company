# 🩹 Surgical & Medical Devices

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `surgical` |
| Vertical | Healthcare |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `service`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Half pharmacy, half electronics dealer: consumables carry batches and expiry, equipment carries serial numbers, warranty and an AMC. The one trade that needs both registers at once.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Equipment under AMC, contracts expiring
- Open service jobs
- Consumable expiry
- Institutional outstanding

## 2. 🩹 Industry menu group — **Surgical & Devices**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-heart-fill`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| AMC Contracts | `/service-contracts` | `service` (Business) | `service-contracts.view` |

- Serial numbers and batches sit side by side here — an instrument is tracked by serial, a dressing by batch.

## 3. 🩹 Surgical & Medical Devices-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Serial numbers — ✅ `/serial-numbers` · `inventory` — equipment
- Batch & expiry — ✅ `/batches` · `inventory` — consumables
- Warranty cards — ✅ `/warranties` · `warranty`
  - Claims — ✅ `/warranties/claims` · `warranty`
- Service jobs — ✅ `/service-jobs` · `service`
- AMC contracts — ✅ `/service-contracts` · `service`
- Institutional / tender sales — ✅ `/sales-orders` · `sales`
- Counter POS — ✅ `/pos` · `pos`
- Devices — ✅ `/devices` · `devices`
- Warranty claim to the principal — ⬜ declared `planned`
- Calibration certificate tracking — ⬜

## 4. Changes to the base

- Service and Warranty are promoted; loyalty is minor.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Receivables ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- AMC renewal pipeline — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- AMC renewal prediction — ⬜

## 7. 📱 On the counter & on the phone

- Barcode and serial capture (`devices`).

Mobile app menu for this trade: Service jobs · AMC · Stock · Serials · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `service`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty.
- **Business** — the working tier — `service` for the bench and `pharmacy` for the consumables side.
- **Professional** — channels.
- **Enterprise** — multi-branch.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `warranty_claim`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

