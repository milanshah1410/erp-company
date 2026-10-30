import 'package:flutter_test/flutter_test.dart';
import 'package:kosha_mobile/core/menu/menu_item.dart';

/// The shape here is exactly what `MenuController@index` returns.
void main() {
  final Map<String, dynamic> response = <String, dynamic>{
    'menu': <dynamic>[
      <String, dynamic>{
        'id': 10,
        'label': 'Sales',
        'icon': 'pi pi-shopping-cart',
        'to': null,
        'permission': null,
        'is_favorite': false,
        'children': <dynamic>[
          <String, dynamic>{
            'id': 11,
            'label': 'POS',
            'icon': 'pi pi-desktop',
            'to': '/pos',
            'permission': 'sales.create',
            'is_favorite': true,
            'children': <dynamic>[],
          },
          <String, dynamic>{
            'id': 12,
            'label': 'Sales Invoice',
            'icon': null,
            'to': '/invoices',
            'permission': 'sales.view',
            'is_favorite': false,
            'children': <dynamic>[],
          },
        ],
      },
    ],
    'favorites': <dynamic>[
      <String, dynamic>{
        'id': 11,
        'label': 'POS',
        'icon': 'pi pi-desktop',
        'to': '/pos',
      },
    ],
    // Recents carry no id — the server stores them as route strings.
    'recents': <dynamic>[
      <String, dynamic>{'label': 'Products', 'icon': null, 'to': '/products'},
    ],
    'denied_routes': <dynamic>['/manufacturing-orders', '/payroll'],
  };

  test('parses the tree, keeping groups and leaves distinct', () {
    final MenuPayload payload = MenuPayload.fromJson(response);

    expect(payload.tree, hasLength(1));
    expect(payload.tree.first.isGroup, isTrue);
    expect(payload.tree.first.children, hasLength(2));
    expect(payload.tree.first.children.first.route, '/pos');
    expect(payload.tree.first.children.first.isGroup, isFalse);
  });

  test('leaves flattens the tree for menu search', () {
    final MenuPayload payload = MenuPayload.fromJson(response);
    final List<String> routes = payload.tree
        .expand((MenuItem i) => i.leaves)
        .map((MenuItem i) => i.route!)
        .toList();

    expect(routes, <String>['/pos', '/invoices']);
  });

  test('recents parse without an id and are not favouritable', () {
    final MenuPayload payload = MenuPayload.fromJson(response);

    expect(payload.recents.single.route, '/products');
    expect(
      payload.recents.single.isCatalogueRow,
      isFalse,
      reason: 'favouriting needs a menu_item_id the server never sent',
    );
    expect(payload.favorites.single.isCatalogueRow, isTrue);
  });

  test('denied routes are readable — hiding a row is not closing the door', () {
    final MenuPayload payload = MenuPayload.fromJson(response);

    expect(payload.isDenied('/payroll'), isTrue);
    expect(payload.isDenied('/pos'), isFalse);
  });

  test('a missing denied_routes key degrades to none, not to everything', () {
    final MenuPayload payload = MenuPayload.fromJson(<String, dynamic>{
      'menu': <dynamic>[],
    });

    expect(payload.deniedRoutes, isEmpty);
    expect(payload.isDenied('/pos'), isFalse);
  });
}
