# 🏍️ Two-Wheeler Workshop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `two_wheeler` |
| Vertical | Automobile |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `service`, `automobile`, `loyalty` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

The same job-card machinery at higher volume and lower value: forty small jobs a day rather than six big ones, and most of them are free-service coupons.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Today's bookings and jobs closed
- Free-service jobs vs. paid
- Parts fast movers
- Pending deliveries

## 2. 🏍️ Industry menu group — **Two-Wheeler**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-car`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Service Bookings | `/automobile/bookings` | `automobile` (Business) | `automobile-bookings.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| Vehicles | `/automobile/vehicles` | `automobile` (Business) | `automobile-vehicles.view` |
| Warranties | `/automobile/warranties` | `automobile` (Business) | `automobile-warranties.view` |

- Four rows: this trade is the auto-service menu with the AMC contract dropped.

## 3. 🏍️ Two-Wheeler Workshop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Service bookings — ✅ `/automobile/bookings` · `automobile`
- Job cards — ✅ `/service-jobs` · `service`
- Vehicles — ✅ `/automobile/vehicles` · `automobile`
- Warranty — ✅ `/automobile/warranties` · `automobile`
- Spare parts & accessories — ✅ `/products`
- Counter POS — ✅ `/pos` · `pos`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Free-service coupon tracking — ⬜ declared `planned`
- Pick-up & drop — ⬜

## 4. Changes to the base

- Service promoted; POS matters more here than in a car garage (accessories sell over the counter).

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales`
- Jobs per day / per technician — ⬜

## 6. 🤖 AI features

- Low stock (spare parts) prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Service-due prediction from the last visit — ⬜
- Job-time estimation — ⬜

## 7. 📱 On the counter & on the phone

- Job-card printer; SMS on completion.

Mobile app menu for this trade: Bookings · Job cards · Parts stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `service`, `automobile`, `loyalty`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and parts inventory.
- **Business** — the working tier (`automobile` + `service`), or Starter + **Automobile Pack**.
- **Professional** — channels.
- **Enterprise** — multi-workshop.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `free_service_coupon`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

