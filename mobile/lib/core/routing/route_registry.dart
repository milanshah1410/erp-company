import 'package:flutter/widgets.dart';

/// Maps the server's route strings onto Flutter screens.
///
/// **This is the architectural keystone of the whole app**, and the answer to
/// the brief's central instruction: *one* application for footwear, garments,
/// grocery, restaurants, manufacturing, logistics and "future industries".
///
/// It works because the server already decides everything:
///
/// * `GET /api/menu` returns a tree of `to` strings — `/products`, `/pos`,
///   `/stock-transfers` — already filtered by permission, plan, module,
///   industry and per-tenant entitlement.
/// * This registry says which of those strings this app build can open.
/// * Anything granted but unregistered renders as "available on the web"
///   instead of a dead menu row or a crash.
///
/// So supporting a new industry is not a new app, or even a new build flag: it
/// is registering more entries here. A footwear shop and a restaurant run the
/// same binary and see different menus because their *workspaces* differ, which
/// is exactly where that difference belongs.
///
/// The catalogue has 319 leaves today; the mobile app will never register all of
/// them, and it does not need to — [isRegistered] is what makes the shortfall
/// graceful rather than broken.
class RouteRegistry {
  const RouteRegistry(this._screens);

  final Map<String, WidgetBuilder> _screens;

  /// Every route this build can open. Compared against the server's catalogue in
  /// a test, so a `MenuSeeder` rename shows up as a failing test rather than as
  /// a screen that silently disappeared from the app.
  Set<String> get registeredRoutes => _screens.keys.toSet();

  bool isRegistered(String route) => _screens.containsKey(_normalise(route));

  /// The screen for a route, or null if this build has none.
  WidgetBuilder? builderFor(String route) => _screens[_normalise(route)];

  /// Trailing slashes and query strings are the two ways the same screen
  /// arrives spelled differently — from a favourite, a deep link, or a
  /// hand-edited menu label. Normalising here means every caller agrees.
  static String _normalise(String route) {
    final String withoutQuery = route.split('?').first;
    if (withoutQuery.length > 1 && withoutQuery.endsWith('/')) {
      return withoutQuery.substring(0, withoutQuery.length - 1);
    }
    return withoutQuery;
  }

  /// Combines registries — core screens plus an industry pack's.
  ///
  /// Later entries win, so an industry pack may deliberately replace a core
  /// screen (a restaurant's "New Sale" is a table plan, not a product grid)
  /// without either side knowing about the other.
  RouteRegistry merge(Map<String, WidgetBuilder> extra) =>
      RouteRegistry(<String, WidgetBuilder>{..._screens, ...extra});
}
