# 🚙 Vehicle Dealership

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `auto_dealer` |
| Vertical | Automobile |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `field_sales`, `gst`, `hrms`, `service`, `automobile`, `loyalty` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Two businesses under one roof: selling vehicles (a lead pipeline, an exchange, a finance file) and servicing them (a workshop). The plugin covers the workshop; the showroom half is largely core CRM.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Enquiries, test drives and bookings in the pipeline
- Vehicle stock by model and colour
- Deliveries scheduled this week
- Workshop load
- Salesman-wise conversions

## 2. 🚙 Industry menu group — **Dealership**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-car`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Vehicles | `/automobile/vehicles` | `automobile` (Business) | `automobile-vehicles.view` |
| Service Bookings | `/automobile/bookings` | `automobile` (Business) | `automobile-bookings.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| Warranties | `/automobile/warranties` | `automobile` (Business) | `automobile-warranties.view` |
| Salesmen | `/salesmen` | `field_sales` (Business) | `salesmen.view` |
| Leads | `/leads` | `crm` (Starter) | `leads.view` |
| Follow-ups | `/follow-ups` | `crm` (Starter) | `follow-ups.view` |

- The vehicle register leads here rather than the booking diary — a dealership's first question is what is in the yard.

## 3. 🚙 Vehicle Dealership-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Vehicle register (VIN) — ✅ `/automobile/vehicles` · `automobile`
- Enquiries & leads — ✅ `/leads` · `crm`
  - Follow-ups — ✅ `/follow-ups` · `crm`
- Salesmen — ✅ `/salesmen` · `field_sales`
  - Targets — ✅ `/salesman-targets` · `field_sales`
- Bookings — ✅ `/automobile/bookings` · `automobile`
- Job cards (workshop) — ✅ `/service-jobs` · `service`
- Warranty — ✅ `/automobile/warranties` · `automobile`
- Spare parts — ✅ `/products`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Vehicle exchange / trade-in — ⬜ declared `planned`
- RTO registration tracking — ⬜ declared `planned`
- Vehicle finance / EMI file — ⬜ declared `planned` (the mobile plugin has one; automobile does not)
- Test-drive scheduling — ⬜

## 4. Changes to the base

- CRM is promoted to the top: the showroom is a pipeline business.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Salesman reports — ✅ `/salesman-reports` · `field_sales`
- Profitability — ✅ `/reports/profitability` · `sales`
- Model-wise / colour-wise sales — ⬜
- Enquiry-to-delivery conversion — ⬜

## 6. 🤖 AI features

- Low stock (spare parts) prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Service-due prediction from the last visit — ⬜
- Job-time estimation — ⬜

## 7. 📱 On the counter & on the phone

- Delivery documents and gate pass (`devices`).
- WhatsApp for test-drive and service reminders.

Mobile app menu for this trade: Leads · Bookings · Vehicle stock · Job cards · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `field_sales`, `gst`, `hrms`, `service`, `automobile`, `loyalty`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — CRM and inventory only.
- **Business** — the working tier (`automobile`, `service`, `field_sales`, `hrms`).
- **Professional** — channels.
- **Enterprise** — multi-outlet dealer groups.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `exchange`
- `rto_registration`
- `vehicle_finance`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

