# 🛞 Tyre & Battery Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `tyre_battery` |
| Vertical | Automobile |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `service`, `automobile`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ⬜ **not written yet** — this file is the spec for it |

Fitment while you wait, and a warranty that is settled pro rata against how much life was left — the only trade in the catalogue where a claim is a partial refund rather than a replacement.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Jobs in the bay
- Serial-tracked stock (battery / tyre numbers)
- Open warranty claims and pro-rata liability
- Fast movers by size

## 2. 🛞 Industry menu group — **Tyre & Battery**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-car`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Service Bookings | `/automobile/bookings` | `automobile` (Business) | `automobile-bookings.view` |
| Service Jobs | `/service-jobs` | `service` (Business) | `service-jobs.view` |
| Vehicles | `/automobile/vehicles` | `automobile` (Business) | `automobile-vehicles.view` |
| Warranties | `/automobile/warranties` | `automobile` (Business) | `automobile-warranties.view` |
| Serial Numbers | `/serial-numbers` | `inventory` (Starter) | `advanced-inventory.view` |

- Serial numbers matter here as much as in electronics: a battery claim is settled on its printed number.

## 3. 🛞 Tyre & Battery Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Bookings — ✅ `/automobile/bookings` · `automobile`
- Job cards (fitment) — ✅ `/service-jobs` · `service`
- Vehicles — ✅ `/automobile/vehicles` · `automobile`
- Warranty — ✅ `/automobile/warranties` · `automobile`
- Serial numbers — ✅ `/serial-numbers` · `inventory` — battery / tyre serials
- Counter POS — ✅ `/pos` · `pos`
- Devices — ✅ `/devices` · `devices`
- Pro-rata warranty claim settlement — ⬜ declared `planned`
- Wheel alignment / balancing as service items — `is_service` products
- Old battery buy-back (scrap value) — ⬜ handled as a discount today

## 4. Changes to the base

- Service and Warranty both promoted; the sales counter is secondary to the bay.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Warranty claim cost — ⬜

## 6. 🤖 AI features

- Low stock (spare parts) prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock — ✅ `/ai-insights` · `ai`
- Service-due prediction from the last visit — ⬜
- Job-time estimation — ⬜

## 7. 📱 On the counter & on the phone

- Serial capture by scanner (`devices`).

Mobile app menu for this trade: Job cards · Stock by size · Warranty lookup · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `service`, `automobile`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS, inventory, warranty.
- **Business** — the working tier (`automobile` + `service`).
- **Professional** — channels.
- **Enterprise** — multi-outlet.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `pro_rata_claim`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

