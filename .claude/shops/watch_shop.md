# ⌚ Watch Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `watch_shop` — ⛔ **not declared in any plugin yet** |
| Vertical | would belong to **Retail** |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `warranty`, `service`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⛔ blocked — needs a trade key first |

⛔ **Not a trade key yet.** Listed as shop 16 in `../shops-modules.md`; no plugin declares it, so a watch shop signs up as "Something else" today and gets the whole catalogue.

Nothing new would have to be built: every screen it needs already exists. It needs a `watch_shop` trade on the Retail plugin with the module set below, and then this menu.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Warranty claims open
- Battery / strap jobs on the bench
- Serial-tracked stock by brand
- Fast movers by brand and price band

## 2. ⌚ Industry menu group — **Watch**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-clock`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |
| Warranty Claims | `/warranties/claims` | `warranty` (Starter) | `warranty-claims.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| AMC Contracts | `/service-contracts` | `service` (Business) | `service-contracts.view` |

- Warranty leads: a watch is bought once and claimed on for two years.
- The bench (battery, strap, servicing) is ordinary `service` job cards — no new module needed.

## 3. ⌚ Watch Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Serial numbers — ✅ `/serial-numbers` · `inventory` — every watch has one
- Warranty cards — ✅ `/warranties` · `warranty`
  - Claims — ✅ `/warranties/claims` · `warranty`
- Service jobs — ✅ `/service-jobs` · `service` — battery replacement, strap, overhaul
- AMC / servicing contract — ✅ `/service-contracts` · `service`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Brand — ✅ `/brands`
- Movement type / case material / water resistance — ⬜ custom fields
- Authorised-service-centre claim to the principal — ⬜

## 4. Changes to the base

- Warranty and Service promoted; otherwise the plain retail base.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Warranty claim cost — ⬜
- Brand-wise sales — ✅ `/reports/sales` · `sales` — filter grouping

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Serial / barcode scanning and label printing (`devices`).

Mobile app menu for this trade: POS · Warranty lookup · Service jobs · Stock

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `warranty`, `service`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty — most of the trade.
- **Business** — adds `service` for the bench.
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

- Blocked on one thing only: declaring `watch_shop` in `RetailPlugin::trades()`.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

