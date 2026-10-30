# API Contract — what the mobile app relies on

Written per §30 of the brief. The full machine-readable spec is generated from
the Laravel source (`php artisan scramble:export` → `backend/docs/openapi.json`,
1,752 operations); this document is the human-sized subset the app depends on,
plus the things a generated spec cannot tell you.

**No endpoint in this document is invented.** Everything listed was read out of
`routes/tenant.php` and its controllers. Anything the app needs and the backend
does not have is marked `TODO(backend)` rather than assumed.

---

## Tenancy — read this first

Tenancy is **carried by the bearer token**. `ResolveTenant` reads `tenant_id` off
the authenticated user, then scopes every query to it.

Consequences:

- **One base URL for every tenant.** Not per-subdomain. The workspace address a
  customer picks at signup (`{sub}.yourapp.com`) is an identity string in the
  platform panel; it is never routed.
- **No tenant header, no tenant parameter.** There is nothing to send and nothing
  to tamper with — §5's "never allow users to manually switch tenant_id" is
  satisfied structurally, not by client discipline.
- Adding a tenant id to a request would create an attack surface the backend
  does not currently have. Don't.

## Headers

| Header | When | Notes |
|---|---|---|
| `Authorization: Bearer <token>` | every authenticated call | from `POST /login` |
| `Accept: application/json` | always | without it Laravel may render HTML errors |
| `X-Locale: en\|hi\|gu` | always | `SetLocale` middleware; makes validation messages come back translated |
| `Idempotency-Key: <uuid>` | retryable writes | see below |

## Response shapes

The brief's §29 envelope (`{success, message, data, errors}`) **does not exist**
on this backend. The real shapes, all four of which the app must handle:

```jsonc
{ "data": [...], "links": {...}, "meta": { "current_page":1, "last_page":9, "per_page":15, "total":128 } }
{ "data": { ... } }                     // single API Resource
{ "token": "...", "user": { ... } }     // bare payload — POST /login
{ "message": "Product deleted." }       // action acknowledgement
```

Decoded by `lib/core/network/api_response.dart`. Errors:

```jsonc
{ "message": "The given data was invalid.", "errors": { "email": ["..."] } }   // 422
{ "message": "...", "error": "tenant_suspended" }                              // 403, typed
```

### Typed error codes

`error` accompanies `message` on the failures where the client must *do*
something different:

| Code | Status | Meaning | App behaviour |
|---|---|---|---|
| `tenant_suspended` | 403 | operator hard-lock | suspended screen, **keep the token** |
| `module_disabled` | 403 | module not on this workspace | explain, no retry |
| `menu_revoked` | 403 | operator withdrew this screen | explain, no retry |
| `tenant_missing` / `tenant_not_found` | 403 | account not attached to a workspace | support problem |
| `idempotency_key_reuse` | 422 | same key, different body | client bug — never retry |
| `idempotency_in_progress` | 409 | first attempt still running | wait `Retry-After`, re-send same key |
| `idempotency_key_invalid` | 400 | key not 8–128 chars | client bug |

## Authentication

| Endpoint | Method | Notes |
|---|---|---|
| `/login` | POST | `{email, password}` → `{token, user}`. **Bad credentials return 422**, not 401 — a `ValidationException` on the `email` field. |
| `/me` | GET | the session payload (below). Re-read every launch; never cached to disk. |
| `/logout` | POST | revokes the current token |
| `/forgot-password` | POST | `{email}` → generic 200 whether or not the address exists. Throttled 6/min per IP plus the broker's per-user throttle. |
| `/reset-password` | POST | `{email, token, password, password_confirmation}`. On success **every token for the account is revoked** — the user signs in again everywhere. |
| `/profile` | GET, PUT/PATCH | |
| `/profile/password` | PUT | `{current_password, password, password_confirmation}` |
| `/profile/avatar` | POST, DELETE | multipart |

**There is no refresh token.** `config/sanctum.php` sets `expiration => null`, so
personal access tokens do not expire and there is nothing to refresh. A 401 means
the token was *revoked* (signed out elsewhere, password reset, deleted by an
admin) and the only correct response is to sign in again.

### Session payload (`/me`, and `user` in the login response)

```jsonc
{
  "id": 3, "name": "…", "email": "…", "phone": "…", "avatar_url": "…|null",
  "roles": ["cashier"],
  "permissions": ["sales.view", "sales.create", …],
  "modules": ["pos", "inventory", …] | null,
  "current_company": { "id":1, "name":"…", "currency_code":"INR", "currency_symbol":"₹", "financial_year_start_month":4 },
  "current_branch": { "id":2, "name":"…" } | null,
  "companies": [...], "branches": [...]
}
```

Two subtleties that the app depends on:

1. **`permissions` is already net of entitlement.** `User::effectivePermissionNames()`
   subtracts permissions belonging to disabled modules and to screens the platform
   operator revoked. So the client must not re-derive entitlement — it reads it.
2. **`modules: null` means "no module gating", not "no modules".** It fails
   **open**, matching `EnsureModuleEnabled`. Treating null as an empty set would
   hide the entire app from workspaces provisioned before module selection existed.

## Navigation — `GET /menu`

The heart of §6. Returns a tree already filtered by permission ∩ plan feature ∩
enabled module ∩ per-tenant operator entitlement.

```jsonc
{
  "menu": [ { "id":10, "label":"Sales", "icon":"pi pi-shopping-cart", "to":null,
              "permission":null, "is_favorite":false,
              "children":[ { "id":11, "label":"POS", "to":"/pos", … } ] } ],
  "favorites": [ { "id":11, "label":"POS", "icon":"…", "to":"/pos" } ],
  "recents":   [ { "label":"Products", "icon":null, "to":"/products" } ],
  "denied_routes": ["/manufacturing-orders", "/payroll"]
}
```

- `to` is a **Vue router path** — the same strings the web SPA uses. They are the
  contract between the two clients; `RouteRegistry` maps them onto Flutter
  screens, and `route_registry_test.dart` asserts every registered key still
  exists in the catalogue.
- Groups have `to: null`. The server already drops groups left empty by filtering.
- `recents` carry **no `id`** (stored as route strings), so they cannot be
  favourited.
- `denied_routes` is the enforcement half of hiding a row: a deep link, stale
  favourite or notification tap must be refused, not merely absent from a menu.

Also: `POST /menu/favorites` `{menu_item_id}`, `POST /menu/recents` `{route, label, icon?}`.

## Idempotency (added for this app — Phase 0)

`Idempotency-Key: <uuid>` on the write endpoints below makes a retry safe. The
first request does the work and its reply is stored; later arrivals with the same
key replay it with `Idempotency-Replayed: true` and the ledger is untouched.

Mint one key **per business intent** (one bill, one payment), not per HTTP
attempt, and persist it with the queued payload so it survives an app restart.

Currently attached to:

`POST /pos/checkout` · `POST /pos/return` · `POST /pos/exchange` ·
`POST /invoices/{invoice}/payments` · `POST /payment-vouchers` ·
`POST /sales-orders` · `POST /stock-adjustments` · `POST /stock-transfers` ·
`POST /expenses`

Fails open: a request without the header behaves exactly as before, which is why
the Vue SPA is unaffected.

## Endpoints the later phases use

Verified present; request/response shapes to be pinned per phase.

| Phase | Endpoints |
|---|---|
| 3 Dashboard | `GET /dashboard/kpis` (tenant-cached 300s), `GET|PUT /dashboard/layout` |
| 4 Masters | `/products` (+`/products/{id}/variants`), `/customers`, `/suppliers`, `/categories`, `/brands`, `/units`, `GET /custom-fields/schema?entity=` |
| 5 POS | `GET /pos/products`, `GET /pos/lookup`, `POST /pos/checkout`, `GET /pos/invoices`, `POST /pos/return`, `POST /pos/exchange`, `GET /barcode/lookup` |
| 6 Purchase/Inventory | `/purchase-orders`, `/goods-receipts`, `/purchase-invoices`, `/stock`, `/stock-adjustments`, `/stock-transfers`, `/cycle-counts`, `/warehouses` |
| 7 Expenses/Reports | `/expenses`, `/expense-categories`, `/reports/*`, `/inventory-reports/*`, `/sales-reports/*` |
| 8 Push | `POST /push-devices` `{token, platform, device_name, app_version}`, `DELETE /push-devices/{id}`, `POST /push-devices/test`, `GET /notifications`, `GET /notifications/unread-count` |
| 12 AI | `POST /ai/search`, `GET /ai/{status,sales-forecast,demand-prediction,purchase-suggestions,low-stock-prediction,report}` |
| — WhatsApp | `POST /whatsapp/messages` — provider credentials stay server-side (§23) |
| — Search | `GET /global-search` (Scout/Meilisearch) |
| — Branding | `GET /branding` (public, host-keyed) for white-label logo/theme |

## Pagination & filtering

`?page=` `?per_page=` (capped at 100) `?search=` `?sort=` `?direction=` plus
per-resource filters (`category_id`, `brand_id`, `is_active`, …). Send nothing
for a cleared filter — `ApiClient` strips empty values, because
`$request->filled()` treats `?category_id=` as present-but-blank and some
controllers then filter on an empty string.

## Known gaps — `TODO(backend)`

- `TODO(backend)` **Offline queue depth.** Idempotency covers replay. It does not
  cover a queued bill whose *referenced rows* changed while offline (a deleted
  product, a price change). Phase 9 needs a decision: reject the whole queued
  transaction, or post it with a stale-price warning.
- `TODO(backend)` **Push topic/targeting.** `PushService` sends per-token. There
  is no per-user notification preference surface yet, so §19's per-category opt-in
  (low stock / new order / payment / approval) has nothing to read.
- `TODO(backend)` **Presigned upload URLs.** Uploads currently go multipart
  through the API. Fine on a shop's Wi-Fi; worth revisiting for large PoD photo
  batches over mobile data.
- `TODO(backend)` **Server-side cursor pagination.** Everything is offset-based
  (`page=`). Deep report pages will get slower as tables grow; not a problem at
  current volumes.
