import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../providers.dart';
import 'menu_item.dart';

/// Reads the navigation the server decided this user may see (§6).
class MenuRepository {
  const MenuRepository(this._api);

  final ApiClient _api;

  /// `GET /api/menu` → tree + favourites + recents + denied routes.
  Future<MenuPayload> fetch() async {
    final Response<dynamic> response = await _api.get('/menu');
    return MenuPayload.fromJson(response.data as Map<String, dynamic>);
  }

  /// `POST /api/menu/favorites` — toggles, and returns the new favourites list.
  Future<List<MenuItem>> toggleFavorite(int menuItemId) async {
    final Response<dynamic> response = await _api.post(
      '/menu/favorites',
      data: <String, dynamic>{'menu_item_id': menuItemId},
    );

    return MenuPayload.fromJson(<String, dynamic>{
      'favorites': (response.data as Map<String, dynamic>)['favorites'],
    }).favorites;
  }

  /// `POST /api/menu/recents` — records a visit.
  ///
  /// Fire-and-forget by design: this is a convenience list, and a failure to
  /// record a visit must never interrupt the navigation the user actually
  /// asked for.
  Future<void> trackRecent({
    required String route,
    required String label,
    String? icon,
  }) =>
      _api.post(
        '/menu/recents',
        data: <String, dynamic>{
          'route': route,
          'label': label,
          if (icon != null) 'icon': icon,
        },
      );
}

final Provider<MenuRepository> menuRepositoryProvider =
    Provider<MenuRepository>(
  (Ref ref) => MenuRepository(ref.watch(apiClientProvider)),
);

/// The menu for the signed-in user.
///
/// Fetched once per session and kept: the catalogue is tenant-stable (the server
/// caches it for 10 minutes itself), and re-fetching it on every drawer open
/// would put a network round trip in front of every navigation.
class MenuNotifier extends AsyncNotifier<MenuPayload> {
  @override
  Future<MenuPayload> build() => ref.watch(menuRepositoryProvider).fetch();

  Future<void> refresh() async {
    state = await AsyncValue.guard(() => ref.read(menuRepositoryProvider).fetch());
  }

  Future<void> toggleFavorite(int menuItemId) async {
    final MenuPayload? current = state.valueOrNull;
    if (current == null) {
      return;
    }

    final List<MenuItem> favorites =
        await ref.read(menuRepositoryProvider).toggleFavorite(menuItemId);

    state = AsyncValue<MenuPayload>.data(
      MenuPayload(
        tree: current.tree,
        favorites: favorites,
        recents: current.recents,
        deniedRoutes: current.deniedRoutes,
      ),
    );
  }

  /// Records a visit without making the caller wait or care.
  void trackRecent(MenuItem item) {
    final String? route = item.route;
    if (route == null) {
      return;
    }

    // Errors are swallowed: see MenuRepository.trackRecent.
    unawaited(
      ref
          .read(menuRepositoryProvider)
          .trackRecent(route: route, label: item.label, icon: item.icon)
          .catchError((Object _) {}),
    );
  }
}

final AsyncNotifierProvider<MenuNotifier, MenuPayload> menuProvider =
    AsyncNotifierProvider<MenuNotifier, MenuPayload>(MenuNotifier.new);
