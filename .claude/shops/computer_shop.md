# 💻 Computer / Laptop Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `computer_shop` |
| Vertical | Computer & IT |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `hrms`, `service`, `computer_it`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Half shop, half support desk. The install base by serial decides whether an engineer travels; the licence-key register is the part no other vertical has; and the call book is the morning screen.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Open service calls and SLA breaches
- AMC contracts expiring in 30 days
- Licence keys expiring
- Machines in the workshop
- Fast-moving components

## 2. 💻 Industry menu group — **Computer & IT**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-desktop`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Service Calls | `/computer-it/service-calls` | `computer_it` (Business) | `it-service-calls.view` |
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |
| Installed Machines | `/computer-it/assets` | `computer_it` (Business) | `it-assets.view` |
| IT Warranties | `/computer-it/warranties` | `computer_it` (Business) | `it-warranties.view` |
| Licence Keys | `/computer-it/licence-keys` | `computer_it` (Business) | `it-licences.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| AMC Contracts | `/service-contracts` | `service` (Business) | `service-contracts.view` |

- Calls lead: a support desk's morning is its open call list. Job Cards and AMC Contracts are second doors onto the shared Service screens.

## 3. 💻 Computer / Laptop Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Service calls — ✅ `/computer-it/service-calls` · `computer_it` — the on-site call book
- Installed machines — ✅ `/computer-it/assets` · `computer_it` — install base by serial
- IT warranties — ✅ `/computer-it/warranties` · `computer_it` — cover decides whether an engineer travels
- Licence keys — ✅ `/computer-it/licence-keys` · `computer_it` — software, per seat, with expiry
- Job cards — ✅ `/service-jobs` · `service`
- AMC contracts — ✅ `/service-contracts` · `service`
- Serial numbers — ✅ `/serial-numbers` · `inventory`
- Accessories & components — ✅ `/products`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Staff / engineers — ✅ `/employees` · `hrms`
- RMA to the principal — ⬜ declared `planned`
- Remote support session log — ⬜ declared `planned`
- Assembled-PC build sheet — ⬜ a BOM would do it, but `manufacturing` is not in the preset

## 4. Changes to the base

- Service is promoted above Sales — the bench and the call book are the business.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales`
- Call / job turnaround — ⬜
- AMC renewal pipeline — ⬜
- Licence expiry register — ⬜ read from the licence screen

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Serial scanning; label printing for asset tags (`devices`).

Mobile app menu for this trade: Service calls · Job cards · Install base · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `hrms`, `service`, `computer_it`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty.
- **Business** — the working tier (`computer_it`, `service`, `hrms`), or Starter + **Computer & IT Pack**.
- **Professional** — channels.
- **Enterprise** — multi-branch, corporate AMC contracts.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `rma`
- `remote_support`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

