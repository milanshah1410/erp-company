# 🧸 Toy & Game Shop

*ERP menu requirement. Retail ERP base + the modules below.*

| | |
|---|---|
| Trade key | `toy_shop` |
| Vertical | Toys & Games |
| Base | [Retail ERP base](_base-retail.md) |
| Industry modules | `pos`, `gst`, `gift`, `toys`, `loyalty`, `devices` |
| Minimum plan | **Business** (or a lower tier + the right marketplace pack) |
| Shipped menu template | ✅ `ShopMenuSeeder::industrySections()` |

Goods for someone who is not in the shop, and regulated because of it. The vertical ships three registers and one counter check: a toy on a lapsed BIS licence is refused at the till.

Wrapping and combo packs are delegated to the `gift` module rather than duplicated.

## 1. 🏠 Dashboard

The base tiles ([Retail base §Dashboard](_base-retail.md#1--dashboard)) plus these, which are
what this trade wants to see first:

- BIS certificates expiring — a lapsed licence looks no different on the shelf
- Age-band coverage of the range
- Wrap queue (shared with gift)
- Festival / birthday-season movers

## 2. 🧸 Industry menu group — **Toys**

One group, inserted **third** in the sidebar — after Dashboard and Sales — because this
is the part of the menu the trade opens every day. Icon `pi pi-star`.

| Screen | Route | Module gate (tier) | Permission |
|---|---|---|---|
| Age Bands | `/toys/age-grades` | `toys` (Starter) | `toy-safety.view` |
| Safety Classes | `/toys/safety-classes` | `toys` (Starter) | `toy-safety.view` |
| Safety Certificates | `/toys/certificates` | `toys` (Starter) | `toy-safety.view` |
| Hamper Packs | `/gift/packs` | `gift` (Starter) | `gift-packs.view` |
| Wrap Desk | `/gift/wraps` | `gift` (Starter) | `gift-wraps.view` |

- Certificates lead, not the age bands: the bands are written once and read forever, while the certificate register is the one thing here with a clock in it.

## 3. 🧸 Toy & Game Shop-specific detail

What the trade actually asks for, and where each piece lives today.
✅ = a screen exists · ⬜ = nothing to point at yet (build or custom field).

- Safety certificates — ✅ `/toys/certificates` · `toys` — BIS licence; expiry refuses the sale
- Safety classes — ✅ `/toys/safety-classes` · `toys` — hazard behind the band
- Age bands — ✅ `/toys/age-grades` · `toys`
- Hamper / combo packs — ✅ `/gift/packs` · `gift` — delegated to `gift`
- Wrap desk — ✅ `/gift/wraps` · `gift` — delegated to `gift`
- Counter POS — ✅ `/pos` · `pos`
- Barcode & label — ✅ `/label-printing`
- Offers & coupons — ✅ `/coupons` · `loyalty`
- Loyalty — ✅ `/loyalty` · `loyalty`
- Coupon issued at the till — ⬜ declared `planned`
- Recall notices — ⬜ declared `planned`

## 4. Changes to the base

- Gift screens appear inside the Toys group — one wrap desk, reached from the trade that uses it.

## 5. 📈 Reports this trade needs

Base reports plus:

- Sales — ✅ `/reports/sales` · `sales`
- Fast / slow moving — ✅ `/reports/fast-slow` · `inventory`
- Age-band-wise sales — ⬜
- Certificate expiry register — ⬜ read from the certificate screen

## 6. 🤖 AI features

- Low stock prediction — ✅ `/ai-insights` · `ai`
- Auto purchase suggestion — ✅ `/ai-insights` · `ai`
- Dead stock recommendation — ✅ `/ai-insights` · `ai`
- Best-selling products — ✅ `/ai-insights` · `ai`
- AI sales summary — ✅ `/ai-insights` · `ai`
- Natural-language ERP search — ✅ `/ai-assistant` · `ai` — bilingual, voice or text

## 7. 📱 On the counter & on the phone

- Barcode scanner and label printer (`devices`).

Mobile app menu for this trade: POS · Certificates · Stock · Wrap queue · Dashboard

## 8. 💡 Plan levels

What a shop gets at each tier. Module tiers are `PlanSeeder`'s, not aspirational —
the trade preset asks for `pos`, `gst`, `gift`, `toys`, `loyalty`, `devices`, so the
workspace needs **Business** for the whole preset to switch on.

- **Starter** — the whole trade — `toys`, `gift`, `pos`, `loyalty`, `devices` are all Starter. Compliance should not be priced out of small shops.
- **Business** — GST, HR.
- **Professional** — channels.
- **Enterprise** — multi-store.

## 9. ⬜ Not buildable into the menu yet

The plugin declares these `planned`, so no menu row may be seeded for them
(`IndustryRegistry::featureAvailable()` returns false):

- `coupon_at_till`
- `recall_notices`


---

[← All shops](README.md) · [Retail ERP base](_base-retail.md)

