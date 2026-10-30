# Flutter Mobile App — Analysis & Implementation Plan

Answers the "FIRST TASK" in [flutter-app.md](flutter-app.md) §35, against the ERP as it
stands on branch `feat/industry-modules`.

**Status: analysis approved; Phases 0–2 are built.** The four decisions in §10 were
settled as: Flutter adapts to the real response shapes; password reset added and
non-expiring tokens kept (covered by biometric lock + auto-logout in Phase 14);
idempotency built up front in Phase 0; first release scoped to the counter-and-owner
app. The Flutter project lives in [`mobile/`](../mobile/) — see
[`mobile/README.md`](../mobile/README.md) and
[`mobile/docs/API_CONTRACT.md`](../mobile/docs/API_CONTRACT.md).

---

## 1. Current project structure

```
erp-company/
  backend/     Laravel 13 + Sanctum + Spatie Permission + Scout/Meilisearch   ← the API
  frontend/    Vue 3 + PrimeVue SPA (221 views)                               ← the web client
  .claude/     requirement docs
```

**There is no Flutter project in this repository.** §35 asks me to inspect the existing
Flutter app, its dependencies, architecture, API integration and auth — none of that exists.
So items 1–5 of §35 are answered here from the *backend* side, which is what the Flutter app
has to consume.

Backend scale (this is the number that shapes every decision below):

| Thing | Count |
|---|---|
| Tenant API route definitions (`routes/tenant.php`) | 734 |
| API controllers (`app/Http/Controllers/Api`) | 198 |
| Central/platform API routes (`routes/api.php`) | ~120 |
| Industry plugins (`app/Modules/Industry`) | 20 |
| Menu leaves in the catalogue (`MenuSeeder`) | 319 |
| Vue screens | 221 |

A Flutter app cannot mirror 319 screens. Scoping to a mobile-shaped subset is the single
most important decision in this plan — see §7.

## 2. Existing dependencies (backend, that the app depends on)

- `laravel/sanctum ^4.3` — bearer tokens, this is the auth the app will use
- `spatie/laravel-permission ^8.1` — team-scoped (tenant-scoped) roles/permissions
- `dedoc/scramble ^0.13` — **OpenAPI generator is already installed** (auto-discovered, no
  `config/scramble.php`). Docs default to `/docs/api` + `/docs/api.json`, gated to `local`
  env by Scramble's `viewApiDocs` gate. This is the §30 path — we generate the contract
  rather than hand-writing it.
- `laravel/scout` + Meilisearch — global search endpoint the app can reuse
- `barryvdh/laravel-dompdf`, `picqer/php-barcode-generator`, `endroid/qr-code` — invoice PDF
  and barcode/label images are produced server-side; the app displays, it does not render.

## 3. Existing architecture the app must live inside

**Tenancy is shared-database, single-host, derived from the token.**
[ResolveTenant.php](../backend/app/Http/Middleware/ResolveTenant.php) reads `tenant_id` off
the authenticated user *after* `auth:sanctum`, then activates the row-level scope and pins
Spatie's team id.

Consequences for Flutter, and they are good ones:

- **No tenant header, no tenant id, no subdomain routing.** One `baseUrl`. The bearer token
  *is* the tenant. §5's "never allow users to manually switch tenant_id" is already
  structurally impossible — there is no parameter to tamper with.
- The workspace subdomain (`{sub}.yourapp.com`) is an identity string only; it is never
  routed. Do **not** build per-tenant base URLs.
- White-label per-tenant branding is served by `GET /api/branding` (public, host-keyed, or
  `?host=`). The app can read the tenant's logo/theme after login from the same controller.

**Middleware stack on every business route:**
`auth:sanctum` → `resolve.tenant` → `tenant.active` → `permission:<name>` (which is
[CheckPermission](../backend/app/Http/Middleware/CheckPermission.php), i.e. Spatie *plus*
per-tenant menu entitlement) and sometimes `module:<key>`.

**The six-way gating in §6 already exists server-side** and is exposed in two payloads:

- `GET /api/me` → `sessionPayload()`: `roles`, `permissions` (already net of disabled
  modules and operator-revoked screens), `modules`, `companies`, `branches`,
  `current_company` (with `currency_code`, `currency_symbol`, `financial_year_start_month`).
- `GET /api/menu` → `{ menu: [tree], favorites: [], recents: [], denied_routes: [] }`, already
  filtered by permission ∩ plan feature ∩ enabled module.

So the Flutter dynamic menu is **not** a rules engine to be written — it is a renderer over
`GET /api/menu`, plus a route-string → Flutter-screen registry. That registry is the entire
"which industries are supported" story: a screen we have not built is simply a menu row we
render as disabled/unsupported.

## 4. Existing API integration conventions (reference: `frontend/src/api/http.ts`)

- `baseURL: /api`, `Accept: application/json`
- `Authorization: Bearer <token>` from storage
- `X-Locale: en|hi|gu` on every request — [SetLocale](../backend/app/Http/Middleware/SetLocale.php)
  makes validation messages come back in that language. **Flutter must send this**, and then
  §28 localization is half-free: server messages are already translated.
- 401 → clear session, go to login
- 403 with `data.error == 'tenant_suspended'` → suspended screen, **keep the token** (the user
  must still be able to sign out / reach billing)
- Other typed error codes to handle: `module_disabled`, `menu_revoked`, `tenant_missing`,
  `tenant_not_found`

## 5. Existing authentication

`POST /api/login {email, password}` → `200 {token, user: sessionPayload}`.
Invalid credentials → `422` (a `ValidationException`, not 401).

| §4 requirement | Backend status |
|---|---|
| Login | ✅ `POST /api/login` |
| Logout | ✅ `POST /api/logout` (revokes current token) |
| Profile | ✅ `GET/PUT /api/profile`, avatar upload/delete |
| Change password | ✅ `PUT /api/profile/password` |
| **Refresh token** | ❌ **does not exist.** Sanctum PATs with `expiration => null` — tokens never expire and there is nothing to refresh. |
| **Forgot / reset password** | ❌ **does not exist.** No route, no controller. |
| Remember session | ✅ implicitly (non-expiring token in secure storage) |

## 6. Missing architecture pieces (the real gaps)

These are the things that must be decided or built before the corresponding Flutter phase
can honestly be called done.

### G1 — Response envelope: the requirement contradicts the backend

§29 assumes `{success, message, data, errors}`. The backend does not do this. It returns
**native Laravel shapes**:

```jsonc
// list  (ProductResource::collection(...->paginate()))
{ "data": [...], "links": {...}, "meta": { "current_page":1, "last_page":9, "per_page":15, "total":128 } }
// single
{ "data": {...} }                       // resource
{ "token": "...", "user": {...} }       // login — no wrapper at all
{ "message": "Product deleted." }       // action
// validation error, 422
{ "message": "...", "errors": { "email": ["..."] } }
```

Retro-fitting an envelope across 198 controllers would break the Vue SPA. **Recommendation:
the Flutter client adapts.** One `ApiResponse<T>` + `Paginated<T>` decoder written against
the *actual* shapes, and §29 is amended in the requirement doc rather than in the backend.

### G2 — No idempotency keys (blocks §18 offline sync)

`grep -i idempoten` over `app/` finds only internal comments about seeders and renewal runs.
There is **no HTTP idempotency mechanism**: `POST /api/pos/checkout` will happily post the
same bill twice. §18 explicitly requires this ("Never blindly duplicate invoices. Use
idempotency keys if backend supports them" — it does not).

Backend work needed: an `Idempotency-Key` middleware + a small `idempotency_keys` table
(key, tenant_id, user_id, endpoint, request hash, response body, status, expires_at) applied
to the write endpoints the app queues offline — checkout, return, exchange, payment receipt,
stock adjustment/transfer, expense. Until this exists, **offline write sync must not ship**;
offline *read* cache can ship safely.

### G3 — No refresh token, no password reset

- Refresh: with non-expiring PATs there is nothing to refresh. Options: (a) accept it, treat
  401 as "sign in again", add biometric app-lock + local auto-logout for the §25 requirement;
  (b) set `sanctum.expiration` and add a `POST /api/refresh` that rotates the token. (a) is
  simpler and matches how the web app already behaves; (b) is the stronger security posture
  for a phone that gets lost.
- Password reset: needs `POST /api/forgot-password` + `POST /api/reset-password` on the
  tenant routes (Laravel's password broker + the tenant's SMTP from white-label settings).
  Small, self-contained. Web has the same gap, so this benefits both clients.

### G4 — Push: server side exists, app side is the missing half

Already built and uncommitted on this branch:
[PushDeviceController](../backend/app/Http/Controllers/Api/PushDeviceController.php),
[PushService](../backend/app/Services/PushService.php) (FCM **HTTP v1** with service-account
JWT, `log` driver by default), `PushChannel`, `PushDevice`.

Contract for the app: `POST /api/push-devices {token, platform: android|ios|web, device_name,
app_version}` on **every app start** (tokens rotate), `DELETE /api/push-devices/{id}` on
logout, `POST /api/push-devices/test` for the settings screen. No permission required — it is
the caller's own handset. So §19 is mostly a Flutter-side job: `firebase_messaging` +
foreground/background/terminated handling + notification tap → deep link into the route
registry.

### G5 — Screen-count mismatch

319 menu leaves vs. a mobile app. There is no version of this where the app covers the whole
catalogue. Needs an explicit, agreed mobile scope (see §7 phases) and a graceful
"open this on the web" fallback for any granted route with no Flutter screen registered.

### G6 — Things that are *not* gaps (worth stating, to avoid re-inventing them)

- Barcode: `GET /api/barcode/lookup`, `GET /api/pos/lookup` — server-side product resolution
  already exists. The scanner package only produces a string.
- Custom fields: `GET /api/custom-fields/schema?entity=product` returns tenant-defined field
  definitions → §8 "dynamic custom fields" is a form renderer, not a schema negotiation.
- Variants (§9 footwear sizes): `product_variants` is core, with per-variant SKU/barcode/
  price/stock; `ProductVariantController` exists. Footwear/garment attributes ride on the
  matrix + custom fields — no footwear-specific API needed.
- AI (§24): `POST /api/ai/search`, `GET /api/ai/{status,sales-forecast,demand-prediction,
  purchase-suggestions,low-stock-prediction,report}` — all keys stay server-side already.
- WhatsApp (§23): `POST /api/whatsapp/messages` — provider credentials are server-side, the
  app only asks for a send. Exactly the §23 flow.
- Dashboard (§7): `GET /api/dashboard/kpis` (tenant-cached 300s) + `GET/PUT
  /api/dashboard/layout` — configurable widgets already exist.
- Global search: `GET /api/global-search` (Scout/Meilisearch).
- GPS (§21): `SalesmanLocationController`, `SalesmanVisitController`, `ProofOfDeliveryController`.

## 7. Recommended Flutter structure

Keep §1's `lib/core | features | industry | routes` layout — it is sound. Two additions
that the backend's shape makes necessary:

```
lib/
  core/
    network/        ApiClient (Dio) + ApiResponse/Paginated decoders (G1) + typed ApiError
    auth/           token store (flutter_secure_storage), session bootstrap
    tenant/         TenantContext from sessionPayload (modules, permissions, currency, plan)
    permissions/    can(), hasModule(), guard widgets
    menu/           menu tree model + MenuService (GET /api/menu)
    routing/        ── ROUTE REGISTRY: Map<String backendRoute, WidgetBuilder> ──
    offline/        connectivity, read cache, outbox queue (gated on G2)
    scanner/        BarcodeScanner abstraction (§11 — package swappable)
    printing/       PrinterService abstraction (§22 — bluetooth | network)
    theme/ widgets/ storage/ errors/ utils/ notifications/ l10n/
  features/<domain>/{data,domain,presentation}
  industry/<vertical>/   registers extra routes into the same registry
```

**The route registry is the architectural keystone.** `GET /api/menu` returns `to: "/products"`
— the same path strings the Vue router uses. The registry maps those to Flutter screens.
Anything granted-but-unregistered renders as "available on web". Industry packs are just
extra registry entries, which is precisely how §Do-not-build-one-app-per-industry is honoured.

## 8. Required packages (justified, per §32 "no random packages")

| Package | Why | Phase |
|---|---|---|
| `flutter_riverpod` + `riverpod_annotation` | §2 mandates Riverpod | 1 |
| `dio` | §3 mandates Dio | 1 |
| `flutter_secure_storage` | §4 mandates it for tokens | 1 |
| `go_router` | route registry needs declarative, name-addressable routes + deep links from notifications | 1 |
| `freezed` + `json_serializable` + `build_runner` | §32 "strong typing"; hand-written models across this many endpoints is not maintainable | 1 |
| `flutter_localizations` + `intl` | §28 en/hi/gu | 1 |
| `shared_preferences` | non-sensitive prefs only (locale, theme) — never tokens | 1 |
| `connectivity_plus` | §18 network detection | 1 |
| `cached_network_image` | §26 image caching | 4 |
| `drift` *or* `sqflite` | §18 offline read cache + outbox | 9 |
| `mobile_scanner` | §11; behind our own abstraction so it is replaceable | 5 |
| `firebase_core` + `firebase_messaging` | §19 | 8 |
| `image_picker` + `flutter_image_compress` | §20, §26 compressed uploads | 13 |
| `geolocator` | §21, on-demand only | 13 |
| `url_launcher` | §14 call / WhatsApp — platform-safe | 4 |
| `local_auth` | §25 optional biometric lock | 14 |
| `printing` / `esc_pos_utils_plus` + `flutter_blue_plus` | §22, behind `PrinterService` | 13 |

No analytics, no crash SDK, no state library beyond Riverpod unless you ask.

## 9. Implementation phases

Same spine as §33, re-cut against what the backend actually offers. Each phase ends with
`flutter analyze` + tests + a report in the §34 format.

| Ph | Scope | Backend prerequisite | State |
|---|---|---|---|
| 0 | Idempotency middleware + table (G2); forgot/reset password (G3); Scramble config + `scramble:export` | — | **done** |
| 1 | Project skeleton, theme (light/dark), l10n (en/hi/gu), `ApiClient` + decoders (G1), secure storage, error taxonomy | none | **done** |
| 2 | Login, session bootstrap (`/me`), `TenantContext`, permission guards, `GET /api/menu` → dynamic drawer, route registry + "open on web" fallback, suspended screen | G3 | **done** |
| 3 | Dashboard (`/dashboard/kpis` + `/dashboard/layout`) | none | next |
| 4 | Customers, Suppliers, Products (+ variants, custom fields, images), search/filter/sort/pagination | none | |
| 5 | POS + barcode scan + Sales Invoice / Return / Exchange | none | |
| 6 | Purchase (PO/GRN/Invoice/Return), Inventory (stock, adjust, transfer, count), Warehouse | none | |
| 7 | Expenses, Reports (server-paginated, §17) | none | |
| 8 | Push: FCM + `/api/push-devices` + deep links | G4 app-side only | |
| 9 | Offline: read cache, connectivity, outbox | ~~G2~~ unblocked | |
| 10 | Footwear pack (size/colour matrix, exchange, warranty) | none | |
| 11 | Further industry packs, one at a time, by your priority | per plugin | |
| 12 | AI Assistant (`/ai/search` + insight endpoints) | none | |
| 13 | Printer, GPS, camera/documents, signature | none | |
| 14 | Tests (§31 list), performance pass, security pass (biometric lock, auto-logout, no prod logging) | — | |
| 15–16 | Android release, iOS release | signing assets | |

## 10. Risks & API-contract requirements

1. **§29 envelope mismatch (G1)** — decide before Phase 1; it shapes every model.
2. **No idempotency (G2)** — Phase 9 cannot ship safely without backend work. A retried POS
   checkout on a flaky counter connection currently double-bills, *today, on the web too*.
3. **No refresh token / no password reset (G3)** — decide before Phase 2.
4. **Scope (G5)** — 319 leaves; agree the mobile subset or the app grows forever.
5. **Permission strings are the contract.** The app must gate on the exact Spatie names
   (`sales.create`, `products.view`, …) returned in `sessionPayload.permissions`. Do not
   invent client-side names. Per §25, this is UX only — Laravel remains the authority.
6. **Menu route strings are the contract.** If a menu row's `to` is renamed in `MenuSeeder`,
   the Flutter registry silently loses a screen. The registry needs a test that asserts every
   registered key still exists in the seeded catalogue.
7. **Locale.** `X-Locale` must be sent or the app shows English validation errors inside a
   Gujarati UI.
8. **File uploads** go through `TenantStorage` with signed URLs; in local dev they stream
   through a signed route. The app must follow redirects and must not cache signed URLs past
   expiry.
9. **`GET /api/me` is heavy-ish** (companies + branches + full permission set). Call it on
   launch and on context switch, cache it, do not poll it.
10. **Suspension is two-tier** — operator suspend blocks login outright; billing suspend
    allows login but is read-only. The app needs both states, not one "suspended" screen.
