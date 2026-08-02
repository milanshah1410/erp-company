# 👓 Optical Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `optical` |
| Vertical | Optical & Eyewear |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `optical`, `loyalty`, `warranty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Written in the order the shop works: the diary is the first screen of the day (who is coming, who is waiting), the test is what the chair produces, the spectacle order is what is made from it, and the collection desk is what leaves.

Warranty cards are issued by an observer on both delivery paths, so a pair collected at the desk and a pair sold over the counter both end up covered.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- Today's appointments and the chair's free slots
- Orders at the lab and due back
- Ready for collection, and uncollected over a week
- Frame stock by brand

## 2. 👓 Industry menu group — **Optical**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-eye`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Spectacle Orders | `/optical-orders` | `optical` (Business) | `optical-orders.view` |
| Eye Prescriptions | `/eye-prescriptions` | `optical` (Business) | `eye-prescriptions.view` |
| Prescriptions | `/prescriptions` | `pharmacy` (Business) | `prescriptions.view` |
| Appointments | `/optical-appointments` | `optical` (Business) | `optical-appointments.view` |
| Collection Desk | `/optical-delivery` | `optical` (Business) | `optical-orders.view` |
| Warranty Cards | `/warranties` | `warranty` (Starter) | `warranties.view` |

- The clash rule only defends named clinical slots — an appointment book is not a general calendar.

## 3. 👓 Optical Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Appointments — ✅ `/optical-appointments` · `optical` — clinical slots, clash-protected
- Eye prescriptions — ✅ `/eye-prescriptions` · `optical` — sph, cyl, axis, add, PD
- Prescription uploads — ✅ `/prescriptions` · `pharmacy` — an outside prescription brought in
- Spectacle orders — ✅ `/optical-orders` · `optical` — frame + lens, sent to the lab
- Collection desk — ✅ `/optical-delivery` · `optical`
- Warranty cards — ✅ `/warranties` · `warranty` — issued on delivery by an observer
- Frames & lenses — ✅ `/products`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Contact-lens fitting record — ⬜ declared `planned`
- Lab / fitting-charge billing — ⬜ declared `planned`
- Insurance / corporate eye-test scheme — ⬜

## 4. Changes to the base

- Appointments are promoted to the top of the day; POS is secondary to the order book.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Profitability — ✅ `/reports/profitability` · `sales` — frame vs. lens margin
- Order turnaround (lab time) — ⬜
- Appointment no-show rate — ⬜

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Label printing for job envelopes (`devices`).
- SMS when the spectacles are ready — the trade's main message.

Mobile app menu for this trade: Appointments · Orders · Collection desk · Stock · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `optical`, `loyalty`, `warranty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — POS and stock — no prescriptions or order book.
- **Business** — the working tier (`optical` + `warranty`), or Starter + **Optical Pack**.
- **Professional** — channels.
- **Enterprise** — multi-store with a central lab.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `contact_lens_fitting`
- `lab_billing`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

