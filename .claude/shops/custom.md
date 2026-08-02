# 🧩 Something else

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `custom` |
| Vertical | — hand-written preset (no plugin) |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | — core only |
| Minimum plan | **Starter** |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The fallback: Core ERP and nothing else. A workspace that picks this gets the whole menu catalogue cloned and prunes it in Tenants → Menu Builder.

This is also what a trade with **no** curated template provisions as today — which is the gap the rest of these files exist to close.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- The base tiles, unmodified.

## 2. 🧩 Industry menu group — **None (full catalogue)**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-sliders-h`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Products | `/products` | — always on | `products.view` |
| Customers | `/customers` | — always on | `customers.view` |
| Suppliers | `/suppliers` | — always on | `suppliers.view` |
| Current Stock | `/stock` | `inventory` (Starter) | `stock.view` |
| Sales | `/reports/sales` | `sales` (Starter)<br>plan feature `reports` | `reports.view` |

- There is no industry group. The five routes above are only listed so this file has the same shape as its siblings.

## 3. 🧩 Something else-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Core ERP only — sales, crm, purchase, inventory, accounts, ai
- Everything else is picked by hand in the onboarding wizard
- Menu is the full catalogue, cloned — pruned per workspace in Tenants → Menu Builder
- Custom fields — ✅ `/custom-fields` — how a trade nobody has named makes the product master its own

## 4. Changes to the base

- Nothing removed and nothing added — this *is* the base.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Purchases — ✅ `/reports/purchases` · `purchase`
- Stock overview — ✅ `/reports/stock-overview` · `inventory`
- Profitability — ✅ `/reports/profitability` · `sales`

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Whatever the workspace switches on.

Mobile app menu for this trade: Dashboard · POS · Products · Inventory · Sales · Reports

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for core only, so the
workspace needs **Starter** for the whole preset to switch on.

- **Starter** — core plus POS, loyalty, assets, workflow, devices, warranty.
- **Business** — the SMB back office and most single-trade verticals.
- **Professional** — channels and production.
- **Enterprise** — everything.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

