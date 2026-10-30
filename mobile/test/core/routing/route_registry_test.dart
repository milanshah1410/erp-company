import 'dart:convert';
import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kosha_mobile/core/routing/route_registry.dart';

/// The guard on the client-to-client contract (risk #6 in the analysis).
///
/// `MenuSeeder` on the Laravel side owns the route strings. If one is renamed —
/// `/stock-transfers` → `/transfers`, say — the app does not break loudly: the
/// menu row simply stops resolving and the screen quietly disappears behind the
/// "available on the web" fallback. Nobody notices for weeks.
///
/// So every registered route is asserted to exist in a snapshot of the real
/// catalogue, exported from the database by
/// `backend/…/export_routes.php` into `test/fixtures/catalogue_routes.json`.
/// Refresh the snapshot after a deliberate rename; a stale snapshot failing is
/// the point.
void main() {
  late Set<String> catalogue;

  setUpAll(() {
    final File file = File('test/fixtures/catalogue_routes.json');
    catalogue = (jsonDecode(file.readAsStringSync()) as List<dynamic>)
        .map((dynamic e) => e.toString())
        .toSet();
  });

  test('the snapshot itself is real', () {
    // Cheap tripwire: an empty or truncated export would make every assertion
    // below vacuous.
    expect(catalogue.length, greaterThan(250));
    expect(catalogue, contains('/products'));
    expect(catalogue, contains('/pos'));
  });

  test('every registered route exists in the server catalogue', () {
    // Imported lazily rather than through the provider: this asserts a property
    // of the map itself and must not need a ProviderContainer to do it.
    const RouteRegistry registry = RouteRegistry(<String, WidgetBuilder>{});

    final Set<String> unknown =
        registry.registeredRoutes.difference(catalogue);

    expect(
      unknown,
      isEmpty,
      reason: 'These routes are registered in the app but no longer exist in '
          'MenuSeeder — they will never be reachable: $unknown',
    );
  });

  group('normalisation', () {
    const RouteRegistry registry = RouteRegistry(<String, WidgetBuilder>{
      '/products': _stub,
    });

    test('matches a plain route', () {
      expect(registry.isRegistered('/products'), isTrue);
    });

    test('ignores a trailing slash', () {
      expect(registry.isRegistered('/products/'), isTrue);
    });

    test('ignores a query string', () {
      expect(registry.isRegistered('/products?category_id=3'), isTrue);
    });

    test('does not match an unregistered route', () {
      expect(registry.isRegistered('/payroll'), isFalse);
      expect(registry.builderFor('/payroll'), isNull);
    });

    test('root is not mangled by the trailing-slash rule', () {
      const RouteRegistry root = RouteRegistry(<String, WidgetBuilder>{
        '/': _stub,
      });
      expect(root.isRegistered('/'), isTrue);
    });
  });

  test('merge lets an industry pack override a core screen', () {
    const RouteRegistry core = RouteRegistry(<String, WidgetBuilder>{
      '/pos': _stub,
    });

    final RouteRegistry withPack =
        core.merge(<String, WidgetBuilder>{'/pos': _otherStub});

    expect(withPack.builderFor('/pos'), same(_otherStub));
    expect(withPack.registeredRoutes, contains('/pos'));
  });
}

Widget _stub(BuildContext context) => const SizedBox.shrink();
Widget _otherStub(BuildContext context) => const SizedBox.shrink();
