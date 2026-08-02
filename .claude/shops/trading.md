# 🌍 Import / Export

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `trading` |
| Vertical | — hand-written preset (no plugin) |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `broker`, `logistics`, `gst` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

One shipment document carries the whole journey; customs and payment are header side-tracks on it, and every board in the menu is a filtered projection of the same table. Profit is derived, never stored.

Overseas parties are flags on ordinary suppliers and customers, and a GRN against a shipment is delegated to the purchase spine rather than re-implemented.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Shipments in transit, by ETA
- Documents pending (BL, COO, insurance)
- Customs clearance pending
- Payments pending (LC, TT)
- Containers at port and detention risk
- Landed cost vs. quoted cost

## 2. 🌍 Industry menu group — **Import & Export**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-globe`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Dashboard | `/import-export` | `logistics` (Business) | `shipments.view` |
| Active Import Shipments | `/import-export/import-shipments` | `logistics` (Business) | `shipments.view` |
| Active Export Shipments | `/import-export/export-shipments` | `logistics` (Business) | `shipments.view` |
| Shipment Timeline | `/import-export/timeline` | `logistics` (Business) | `shipments.view` |
| ETA / ETD | `/import-export/eta-etd` | `logistics` (Business) | `shipments.view` |
| Pending Documents | `/import-export/documents` | `logistics` (Business) | `shipment-documents.view` |
| Pending Customs | `/import-export/customs` | `logistics` (Business) | `shipment-customs.view` |
| Pending Payments | `/import-export/payments` | `logistics` (Business) | `shipment-payments.view` |
| Container Status | `/import-export/containers` | `logistics` (Business) | `shipment-containers.view` |
| Shipment Profit | `/import-export/profit` | `logistics` (Business) | `shipments.view` |
| Logistics Expenses | `/import-export/expenses` | `logistics` (Business) | `shipment-expenses.view` |

- Every row here is one shipment table read a different way — which is why adding a board costs a query, not a document type.

## 3. 🌍 Import / Export-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Dashboard — ✅ `/import-export` · `logistics`
- Import shipments — ✅ `/import-export/import-shipments` · `logistics`
- Export shipments — ✅ `/import-export/export-shipments` · `logistics`
- Timeline — ✅ `/import-export/timeline` · `logistics`
- ETA / ETD — ✅ `/import-export/eta-etd` · `logistics`
- Documents — ✅ `/import-export/documents` · `logistics`
- Customs — ✅ `/import-export/customs` · `logistics`
- Payments — ✅ `/import-export/payments` · `logistics`
- Containers — ✅ `/import-export/containers` · `logistics`
- Shipment profit — ✅ `/import-export/profit` · `logistics` — derived, not stored
- Logistics expenses — ✅ `/import-export/expenses` · `logistics`
- Masters
  - Countries — ✅ `/countries` · `logistics`
  - Ports — ✅ `/ports` · `logistics`
  - Shipping lines — ✅ `/shipping-lines` · `logistics`
  - Airlines — ✅ `/airlines` · `logistics`
  - Freight forwarders — ✅ `/freight-forwarders` · `logistics`
  - CHA agents — ✅ `/cha-agents` · `logistics`
  - Transporters — ✅ `/transporters` · `logistics`
  - Overseas suppliers — ✅ `/overseas-suppliers` · `logistics`
  - Overseas customers — ✅ `/overseas-customers` · `logistics`
- Broker — ✅ `/brokers` · `broker`
- Letter of credit register — ⬜ payments are tracked, the LC instrument is not
- Duty drawback / export incentive — ⬜

## 4. Changes to the base

- POS and Loyalty dropped.
- Logistics replaces the industry group entirely — it *is* the trade.

## 5. 📈 Reports this trade needs

Base reports plus:

- Import report — ✅ `/reports/import-shipments` · `logistics`
- Export report — ✅ `/reports/export-shipments` · `logistics`
- Shipment profit — ✅ `/reports/shipment-profit` · `logistics`
- Shipment expense — ✅ `/reports/shipment-expenses` · `logistics`
- Customs report — ✅ `/reports/shipment-customs` · `logistics`
- Container report — ✅ `/reports/shipment-containers` · `logistics`
- Country-wise sales — ✅ `/reports/country-wise-sales` · `logistics`
- Port-wise shipments — ✅ `/reports/port-wise-shipments` · `logistics`

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Purchase suggestion — ✅ `/ai-insights` · `ai`
- Profit analysis — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai`

## 7. 📱 On the counter & on the phone

- Document scanning and attachment against the shipment.
- No carrier or customs API — status is keyed in.

Mobile app menu for this trade: Shipments · ETA board · Documents · Payments · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `broker`, `logistics`, `gst`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — books and stock only.
- **Business** — the working tier (`logistics`, `gst`, `broker`), or Starter + **Logistics & EXIM Pack**.
- **Professional** — channels.
- **Enterprise** — multi-entity, API.

---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

