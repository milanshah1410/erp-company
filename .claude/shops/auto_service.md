# 🚗 Car Service Centre / Garage

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `auto_service` |
| Vertical | Automobile |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `hrms`, `service`, `automobile`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

A job card **is** a service job with a vehicle attached — the plugin ships only what is peculiar to vehicles: the VIN register, the appointment book, and warranty that ends on date **or** kilometres, whichever comes first.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Today's bookings and bays free
- Open job cards by stage
- Vehicles awaiting parts
- Technician-wise load
- Warranty jobs vs. paid jobs

## 2. 🚗 Industry menu group — **Automobile**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-car`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Service Bookings | `/automobile/bookings` | `automobile` (Business) | `automobile-bookings.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| Vehicles | `/automobile/vehicles` | `automobile` (Business) | `automobile-vehicles.view` |
| Warranties | `/automobile/warranties` | `automobile` (Business) | `automobile-warranties.view` |
| AMC Contracts | `/service-contracts` | `service` (Business) | `service-contracts.view` |

- Bookings lead: the appointment book is the only screen that says what today looks like before any of it has happened.
- "Job Cards" is a second door onto `/service-jobs` — one job book, reachable from Service and from Automobile.

## 3. 🚗 Car Service Centre / Garage-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Service bookings — ✅ `/automobile/bookings` · `automobile`
- Job cards — ✅ `/service-jobs` · `service` — labour bills as an `is_service` product
- Vehicles (VIN / registration) — ✅ `/automobile/vehicles` · `automobile`
- Vehicle warranty — ✅ `/automobile/warranties` · `automobile` — ends on date OR km, whichever first
- AMC contracts — ✅ `/service-contracts` · `service`
- Spare parts — ✅ `/products`
  - Parts issue against a job — on the job card
- Technicians — ✅ `/employees` · `hrms`
- Counter POS — ✅ `/pos` · `pos` — over-the-counter parts
- Estimate / approval before work — quotations
- Insurance claim job — ⬜ declared `planned`
- Pick-up & drop scheduling — ⬜
- Service history by vehicle — reachable from the vehicle record

## 4. Changes to the base

- Service is the operational core, so the Service group is promoted above Purchase.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales` — labour vs. parts
- Profitability — ✅ `/reports/profitability` · `sales`
- Job-card turnaround — ⬜
- Technician productivity — ⬜

## 6. 🤖 AI features

- Low stock (spare parts) prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Service-due prediction from the last visit — ⬜
- Job-time estimation — ⬜

## 7. 📱 On the counter & on the phone

- Job-card and gate-pass printing (`devices`).
- SMS / WhatsApp when the vehicle is ready.

Mobile app menu for this trade: Bookings · Job cards · Vehicles · Parts stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `hrms`, `service`, `automobile`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty — a parts counter with no workshop.
- **Business** — the working tier (`automobile` + `service` + `hrms`), or Starter + **Automobile Pack**.
- **Professional** — channels for online parts sales.
- **Enterprise** — multi-workshop.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `insurance_claim`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

