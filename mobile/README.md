# Kosha Mobile

One Flutter app for every industry the ERP serves — footwear, garments, grocery,
supermarket, mobile, electronics, computer, restaurant, bakery, dairy,
manufacturing, wholesale, distribution, automobile, healthcare, agriculture,
service, logistics, construction, and whatever comes next.

**Not one app per industry.** The same binary serves all of them, because the
menu and the features come from the server, not from the build.

## How that works

```
GET /api/menu                    →  a tree of route strings the server already
                                    filtered by permission ∩ role ∩ module ∩
                                    industry ∩ plan ∩ feature flag
        ↓
RouteRegistry                    →  which of those strings this build can open
        ↓
   registered  →  the screen
   not built   →  "available on the web" (with a link)
   denied      →  refused, even from a deep link or a stale favourite
```

Supporting a new industry means adding entries to
[`lib/routes/screen_routes.dart`](lib/routes/screen_routes.dart) — not a new app,
not a build flavour, not a feature flag. A footwear shop and a restaurant run the
same APK and see different menus because their *workspaces* differ.

Read [`lib/core/routing/route_registry.dart`](lib/core/routing/route_registry.dart)
first; it is the keystone.

## First run

The platform folders are not committed. Generate them, then build:

```sh
cd mobile
flutter create --platforms=android,ios --project-name kosha_mobile .   # adds android/ ios/ only
flutter pub get                                                        # also runs gen-l10n
flutter analyze
flutter test
```

`flutter create` on an existing project fills in what is missing and leaves
`lib/`, `pubspec.yaml` and `test/` alone.

Run against a local WAMP backend:

```sh
flutter run \
  --dart-define=API_BASE_URL=http://10.0.2.2/api \
  --dart-define=APP_ENV=development
```

`10.0.2.2`, not `localhost` — on the Android emulator, localhost is the handset.

Release:

```sh
flutter build apk --release \
  --dart-define=API_BASE_URL=https://yourapp.com/api \
  --dart-define=APP_ENV=production
```

`APP_ENV=production` is what switches network logging off (§25). It is not
optional.

## Layout

```
lib/
  core/
    config/      build-time settings via --dart-define; no secrets, ever
    network/     ApiClient (Dio) + the response decoders + idempotency helpers
    errors/      the sealed ApiException taxonomy every layer switches on
    auth/        TokenStore — keychain-backed, shared by network and auth
    tenant/      TenantContext: who is signed in and what they may do
    menu/        the server-driven navigation
    routing/     RouteRegistry ← the keystone
    storage/     secure (tokens) vs prefs (locale, theme) — deliberately split
    theme/  widgets/  l10n/
  features/<domain>/{data,application,presentation}
  routes/        router + the registry's contents
  l10n/          app_en.arb, app_hi.arb, app_gu.arb
```

## Conventions

- **Repositories throw `ApiException`, never `DioException`.** Nothing above
  `core/network` imports Dio.
- **Permission checks are UX only** (§25). `tenant.can('sales.create')` decides
  whether to *show* a button. Laravel decides whether it works.
- **Never store a tenant id.** The bearer token carries the workspace.
- **Never cache permissions to disk.** They are re-read from `/me` each launch,
  so a revocation takes effect on the next launch rather than whenever a cache
  expires.
- **Mint an idempotency key per business intent**, not per HTTP attempt, and
  persist it with the queued payload. See
  [`lib/core/network/idempotency.dart`](lib/core/network/idempotency.dart).
- **No hardcoded UI strings** (§28). Add to `app_en.arb` and translate.

## Status

Verified on **Flutter 3.44.9 / Dart 3.12.2**: `flutter analyze` → *No issues found*,
`flutter test` → **42 passing**.

| Phase | Scope | State |
|---|---|---|
| 0 | Backend prerequisites: idempotency, password reset, OpenAPI export | done |
| 1 | Skeleton, theme, l10n, ApiClient, secure storage, error taxonomy | done |
| 2 | Auth, TenantContext, permission guards, dynamic menu, route registry | done |
| 3 | Dashboard KPIs (`/dashboard/kpis` + saved layout) | next |
| 4 | Customers, suppliers, products (+ variants, custom fields) | |
| 5 | POS, barcode, sales invoice / return / exchange | |
| 6 | Purchase, inventory, warehouse | |
| 7 | Expenses, reports | |
| 8 | Push (FCM + `/push-devices`) | |
| 9 | Offline read cache + outbox | unblocked — Phase 0 shipped the idempotency it needed |
| 10–11 | Footwear pack, then further industry packs | |
| 12 | AI assistant | |
| 13 | Printer, GPS, camera, documents | |
| 14–16 | Tests, performance, security; Android and iOS release | |

## See also

- [`docs/API_CONTRACT.md`](docs/API_CONTRACT.md) — the endpoints, shapes, error
  codes and known backend gaps. Read before writing a repository.
- [`../.claude/flutter-app-plan.md`](../.claude/flutter-app-plan.md) — the
  analysis this project was scoped from.
- `backend/docs/openapi.json` — the full generated spec (`php artisan
  scramble:export`; gitignored, ~10 MB).
