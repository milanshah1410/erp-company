import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/menu/menu_item.dart';
import '../core/menu/menu_repository.dart';
import '../core/routing/route_registry.dart';
import '../features/shell/presentation/screen_unavailable.dart';

/// App-owned routes — the handful that exist regardless of what the server says,
/// because the app needs somewhere to be before it has asked.
class AppRoutes {
  const AppRoutes._();

  static const String splash = '/_splash';
  static const String login = '/_login';
  static const String forgotPassword = '/_forgot-password';
  static const String suspended = '/_suspended';

  /// Matches the server's catalogue, where the dashboard is `/`. It is also the
  /// fallback the API never puts in `denied_routes` — precisely so the app
  /// always has somewhere to land.
  static const String dashboard = '/';

  /// The underscore prefix is not decoration: it keeps these four from ever
  /// colliding with a server route string. The catalogue owns the entire
  /// unprefixed namespace (`/products`, `/pos`, …) and may add to it at any
  /// time without knowing this app exists.
  static bool isAppOwned(String route) => route.startsWith('/_');
}

/// The screens this build can open.
///
/// **This map is the app's scope.** It starts with the counter-and-owner set
/// agreed for the first release; Phases 4–7 fill it in, and Phases 10–11 add
/// industry packs by merging further maps into it.
///
/// Keys must match `MenuSeeder`'s `to` values exactly — that is the contract
/// between the two clients, and `route_registry_test.dart` asserts it against a
/// checked-in snapshot of the catalogue so a rename fails the build rather than
/// quietly removing a screen from the app.
final Provider<RouteRegistry> routeRegistryProvider =
    Provider<RouteRegistry>((Ref ref) {
  return const RouteRegistry(<String, WidgetBuilder>{
    // Phase 4–7 register their screens here. Left deliberately empty rather
    // than filled with placeholders: an entry here is a promise that the screen
    // works, and a stub that renders "coming soon" is worse than the honest
    // ScreenUnavailable the fallback already provides.
  });
});

/// Resolves a server route to a screen at navigation time.
///
/// The three-way decision that lets one app serve every industry — see
/// [RouteRegistry] for why this shape was chosen.
class DynamicScreen extends ConsumerWidget {
  const DynamicScreen({required this.route, super.key});

  final String route;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final MenuPayload? menu = ref.watch(menuProvider).valueOrNull;

    // Refused before it is resolved: a route the server listed as denied must
    // not open even if this build happens to have a screen registered for it.
    // This is the enforcement half of hiding a menu row.
    if (menu != null && menu.isDenied(route)) {
      return ScreenUnavailable(route: route, reason: UnavailableReason.denied);
    }

    final WidgetBuilder? builder =
        ref.watch(routeRegistryProvider).builderFor(route);

    if (builder == null) {
      return ScreenUnavailable(
        route: route,
        reason: UnavailableReason.notInApp,
        label: _labelFor(menu, route),
      );
    }

    return builder(context);
  }

  /// The menu's own wording for this screen, so the fallback says
  /// "Purchase Orders is available on the web" rather than quoting a URL. Tenants
  /// rename menu rows, and the rename is what their staff recognise.
  String? _labelFor(MenuPayload? menu, String route) {
    if (menu == null) {
      return null;
    }

    for (final MenuItem item in menu.tree.expand((MenuItem i) => i.leaves)) {
      if (item.route == route) {
        return item.label;
      }
    }
    return null;
  }
}
