# 🎨 Paint Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `paint_shop` — ⛔ **not declared in any plugin yet** |
| Vertical | would belong to **Retail** or **Distribution** |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `devices`, `loyalty`, `logistics` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⛔ blocked — needs a trade key first |

⛔ **Not a trade key yet.** Listed as shop 18 in `../shops-modules.md`.

Unlike the watch shop, this one is **not** just a missing trade key: colour mixing is a genuine build. A tint machine turns one base into thousands of shades, so the shop stocks bases and formulas rather than finished products — and nothing in the catalogue models a formula.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Base stock by pack size
- Shades mixed today
- Batch / expiry on base and tinters
- Contractor accounts and outstanding

## 2. 🎨 Industry menu group — **Paint**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-palette`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Batches / Lots | `/batches` | `inventory` (Starter) | `advanced-inventory.view` |
| Credit Control | `/credit-control` | `distribution` (Business) | `credit-control.view` |
| Devices | `/devices` | `devices` (Starter) | `devices.view` |
| Delivery Routes | `/delivery-routes` | `logistics` (Business) | `delivery-routes.view` |

- Only four rows exist today, and none of them is the thing the trade actually needs.

## 3. 🎨 Paint Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Batch & expiry — ✅ `/batches` · `inventory` — base and tinter batches
- Contractor credit — ✅ `/credit-control` · `distribution`
- Devices — ✅ `/devices` · `devices`
- Delivery — ✅ `/delivery-routes` · `logistics`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Shade code master (fan deck) — ⬜ **build**: nothing models a shade catalogue
- Colour mixing formula storage — ⬜ **build**: base + tinter quantities per shade per pack size
- Tint machine integration — ⬜ **build**: no device adapter
- Mixed-on-demand stock (base depletes, shade is never stocked) — ⬜ **build**
- Contractor / painter loyalty scheme — core loyalty would do it

## 4. Changes to the base

- Would need a `paint` module with a shade + formula register before the menu is worth curating.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Shade-wise / base-wise consumption — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Tint machine would need a `DeviceManager` adapter — the plugin architecture supports it (per-brand adapters + simulator mode), but none is written.

Mobile app menu for this trade: POS · Stock · Contractor outstanding

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `devices`, `loyalty`, `logistics`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and inventory — a paint shop can bill, but not mix.
- **Business** — GST, logistics, credit control.
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

- Real build, not just a trade key: shade master, formula storage, mixed-on-demand stock and a tint-machine adapter.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

