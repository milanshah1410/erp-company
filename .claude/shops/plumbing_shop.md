# 🚰 Plumbing / Sanitary Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `plumbing_shop` — ⛔ **not declared in any plugin yet** |
| Vertical | would belong to **Distribution** (beside `hardware_wholesale`) |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `distribution`, `logistics`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⛔ blocked — needs a trade key first |

⛔ **Not a trade key yet.** Listed as shop 20 in `../shops-modules.md`.

In practice this is `hardware_wholesale` with different attributes — pipe size, fitting type, material — and those are custom fields rather than a module. The cheapest honest answer is to declare it as a Distribution trade reusing that template.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Outstanding by plumber / contractor
- Deliveries pending
- Bundle stock by size
- Fast movers by material (CPVC / UPVC / GI)

## 2. 🚰 Industry menu group — **Plumbing**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-filter`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Transporters | `/transporters` | `logistics` (Business) | `transporters.view` |
| Transport Charges | `/transport-charges` | `logistics` (Business) | `transport-charges.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |
| Proof of Delivery | `/proof-of-delivery` | `logistics` (Business) | `dispatch-pods.view` |

- Identical to the hardware template — which is the argument for making it a trade rather than a module.

## 3. 🚰 Plumbing / Sanitary Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Credit control — ✅ `/credit-control` · `distribution` — plumbers buy on account
- Transport — ✅ `/transporters` · `logistics`
  - Charges — ✅ `/transport-charges` · `logistics`
- Delivery routes — ✅ `/delivery-routes` · `logistics`
  - Proof of delivery — ✅ `/proof-of-delivery` · `logistics`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Pipe size / fitting type / material attributes — ⬜ custom fields
- Bundle stock (pieces per bundle) — ⬜ second unit of measure
- Cut-length sale — ⬜
- Sanitaryware display / showroom stock — ordinary stock

## 4. Changes to the base

- Would reuse the `hardware_wholesale` template verbatim.

## 5. 📈 Reports this trade needs

Base reports plus:

- Outstanding & ageing — ✅ `/reports/receivables-ageing` · `distribution`
- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Barcode and delivery POD (`devices`).

Mobile app menu for this trade: POS · Outstanding · Delivery / POD · Stock

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `distribution`, `logistics`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory.
- **Business** — the working tier (`distribution` + `logistics`).
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

- Blocked only on a trade key. No new screens required — declare `plumbing_shop` on the Distribution plugin and point it at the hardware template.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

