/// One node of the server-driven navigation (§6).
///
/// The critical thing to understand about this type: **the app does not decide
/// what is in it.** `GET /api/menu` returns a tree already filtered by
/// permission ∩ subscription plan feature ∩ enabled module ∩ per-tenant operator
/// entitlement. The six-way gating §6 asks for is implemented once, on the
/// server, where it is also enforced — so re-implementing it here would only
/// create a second opinion that can disagree with the one that matters.
///
/// The app's job is to render this tree and map [route] onto a screen.
class MenuItem {
  const MenuItem({
    required this.id,
    required this.label,
    required this.children,
    this.icon,
    this.route,
    this.permission,
    this.isFavorite = false,
  });

  factory MenuItem.fromJson(Map<String, dynamic> json) => MenuItem(
        // Favourites carry an id; recents do not (the server stores them as
        // route strings). -1 marks "not a catalogue row" — see [isCatalogueRow],
        // which is what guards favouriting.
        id: (json['id'] as num?)?.toInt() ?? -1,
        label: json['label'] as String? ?? '',
        icon: json['icon'] as String?,
        // The API calls this `to` — a Vue router path such as `/products`.
        // These strings are the contract between the two clients; see
        // RouteRegistry.
        route: json['to'] as String?,
        permission: json['permission'] as String?,
        isFavorite: json['is_favorite'] as bool? ?? false,
        children: switch (json['children']) {
          final List<dynamic> list => list
              .whereType<Map<String, dynamic>>()
              .map(MenuItem.fromJson)
              .toList(growable: false),
          _ => const <MenuItem>[],
        },
      );

  final int id;
  final String label;
  final String? icon;

  /// The server's route string, e.g. `/products`, `/pos`, `/stock-transfers`.
  /// Null on a group.
  final String? route;

  final String? permission;
  final bool isFavorite;
  final List<MenuItem> children;

  /// A group exists to hold children; a leaf is something you can open.
  /// The server already drops groups that ended up empty after filtering.
  bool get isGroup => route == null;

  /// Whether this node corresponds to a real `menu_items` row, and so can be
  /// favourited (`POST /api/menu/favorites` takes a `menu_item_id`). Recents
  /// cannot: the server never told us their id.
  bool get isCatalogueRow => id > 0;

  /// Depth-first leaves — for the menu search box, which should find "Stock
  /// Transfer" without the user first knowing it lives under Inventory.
  Iterable<MenuItem> get leaves sync* {
    if (!isGroup) {
      yield this;
    }
    for (final MenuItem child in children) {
      yield* child.leaves;
    }
  }
}

/// The full payload of `GET /api/menu`.
class MenuPayload {
  const MenuPayload({
    required this.tree,
    required this.favorites,
    required this.recents,
    required this.deniedRoutes,
  });

  factory MenuPayload.fromJson(Map<String, dynamic> json) => MenuPayload(
        tree: _items(json['menu']),
        favorites: _items(json['favorites']),
        recents: _items(json['recents']),
        deniedRoutes: switch (json['denied_routes']) {
          final List<dynamic> list =>
            list.map((dynamic e) => e.toString()).toSet(),
          _ => const <String>{},
        },
      );

  final List<MenuItem> tree;
  final List<MenuItem> favorites;

  /// Recently visited screens. These carry no `id` from the server (they are
  /// stored as route strings), so [MenuItem.id] is synthesised — never use it
  /// to favourite a recent.
  final List<MenuItem> recents;

  /// Screens in this workspace's catalogue that this user may **not** open.
  ///
  /// The server sends these explicitly so the client can refuse a deep link,
  /// a notification tap or a stale favourite — hiding a menu row is not the
  /// same as closing the door behind it.
  final Set<String> deniedRoutes;

  bool isDenied(String route) => deniedRoutes.contains(route);

  /// One parser for all three lists. Favourites and recents are flatter than
  /// the tree — no `children`, and recents have no `id` — which
  /// [MenuItem.fromJson] already tolerates, so they need no separate model.
  static List<MenuItem> _items(dynamic value) => switch (value) {
        final List<dynamic> list => list
            .whereType<Map<String, dynamic>>()
            .map(MenuItem.fromJson)
            .toList(growable: false),
        _ => const <MenuItem>[],
      };
}
