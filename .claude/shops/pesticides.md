# 🐛 Pesticides / Agro Chemicals

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `pesticides` |
| Vertical | Agriculture |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `field_sales`, `gst`, `agriculture`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The most regulated agri counter. Restricted molecules, expiry that is a legal matter rather than a markdown, and a licence that must be shown on demand.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Licence validity and renewal due
- Expiry board — chemicals past date cannot be sold
- Village-wise offtake by crop
- Restricted-item movement

## 2. 🐛 Industry menu group — **Agro Chemicals**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-sun`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Input Licences | `/agri-licences` | `agriculture` (Business) | `agri-licences.view` |
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Beat Plans & Routes | `/beats` | `field_sales` (Business) | `beats.view` |

- Expiry is enforced at the till by the same mechanism cosmetics uses — a refusal, not a warning.

## 3. 🐛 Pesticides / Agro Chemicals-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Pesticide licence — ✅ `/agri-licences` · `agriculture`
- Batch & expiry — ✅ `/batches` · `inventory` — expiry blocks the sale
- Molecule / formulation attributes — product master
- Village routes & field team — ✅ `/beats` · `field_sales`
- Counter POS — ✅ `/pos` · `pos`
- Restricted-sale register (who bought what) — ⬜ declared `planned`
- Spray advisory / dosage guidance — ⬜ declared `planned`
- Empty-container return / disposal — ⬜

## 4. Changes to the base

- Batch & expiry promoted; loyalty irrelevant.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Stock ageing — ✅ `/reports/stock-ageing` · `inventory`
- Expiry-due register — ⬜
- Crop-wise / molecule-wise sales — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Expiry-risk advice — ⬜

## 7. 📱 On the counter & on the phone

- Barcode and counter printer (`devices`).

Mobile app menu for this trade: POS · Stock · Expiry board · Licences

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `field_sales`, `gst`, `agriculture`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory.
- **Business** — the working tier (`agriculture` + `field_sales`).
- **Professional** — not needed.
- **Enterprise** — multi-branch.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `restricted_sale_register`
- `spray_advisory`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

