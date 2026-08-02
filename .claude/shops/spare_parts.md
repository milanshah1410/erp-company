# 🔧 Spare Parts & Accessories

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `spare_parts` |
| Vertical | Automobile |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `automobile`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A counter, not a workshop. The whole difficulty is fitment — "does this fit a 2016 Swift?" — and that is the one thing the catalogue cannot yet answer.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Fast-moving parts by vehicle model
- Dead stock (parts for vehicles nobody drives any more)
- Counter sales and returns
- Warranty claims on parts

## 2. 🔧 Industry menu group — **Spare Parts**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-car`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Vehicles | `/automobile/vehicles` | `automobile` (Business) | `automobile-vehicles.view` |
| Warranties | `/automobile/warranties` | `automobile` (Business) | `automobile-warranties.view` |
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |

- The vehicle register is used as a lookup here rather than as an owned-asset list — the counter searches by model, not by VIN.

## 3. 🔧 Spare Parts & Accessories-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Vehicles (model lookup) — ✅ `/automobile/vehicles` · `automobile`
- Warranty — ✅ `/automobile/warranties` · `automobile`
- Serial numbers — ✅ `/serial-numbers` · `inventory` — for serialised parts
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Fitment catalogue (part ↔ model / year) — ⬜ declared `planned`; the trade's biggest gap
- OEM / aftermarket part-number cross-reference — ⬜
- Core / old-part return — ⬜

## 4. Changes to the base

- No service group at all — this trade has no bench.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Dead stock — ✅ `/dead-stock` · `inventory`
- Model-wise part sales — ⬜ blocked on the fitment catalogue

## 6. 🤖 AI features

- Low stock (spare parts) prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Service-due prediction from the last visit — ⬜
- Job-time estimation — ⬜

## 7. 📱 On the counter & on the phone

- Barcode scanner and label printer (`devices`).

Mobile app menu for this trade: POS · Stock check · Customers · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `automobile`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty — nearly enough for this trade.
- **Business** — adds the `automobile` register and GST.
- **Professional** — channels — parts sell well online.
- **Enterprise** — multi-branch.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `fitment_catalogue`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

