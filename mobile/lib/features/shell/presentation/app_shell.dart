import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/menu/menu_item.dart';
import '../../../core/menu/menu_repository.dart';
import '../../../core/tenant/tenant_context.dart';
import '../../../core/widgets/app_state_views.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/application/session_controller.dart';
import 'menu_drawer.dart';

/// The frame every signed-in screen sits in.
///
/// Holds the drawer so the menu is fetched once per session rather than rebuilt
/// per screen, and so navigating between screens does not tear down and
/// re-create the navigation itself.
class AppShell extends ConsumerWidget {
  const AppShell({required this.child, super.key});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final TenantContext? tenant = ref.watch(tenantContextProvider);

    // The router guarantees we are signed in here; this guards the single frame
    // between sign-out and the redirect landing.
    if (tenant == null) {
      return const Scaffold(body: AppLoading());
    }

    return Scaffold(
      drawer: const MenuDrawer(),
      body: child,
    );
  }
}

/// The app bar business screens share.
///
/// A widget rather than something baked into [AppShell] because go_router's
/// ShellRoute cannot know a screen's title — the screen does, and it takes its
/// title from the *menu label*, which is the tenant's own wording.
class AppTopBar extends ConsumerWidget implements PreferredSizeWidget {
  const AppTopBar({required this.title, super.key, this.actions});

  final String title;
  final List<Widget>? actions;

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context, WidgetRef ref) => AppBar(
        title: Text(title),
        actions: actions,
      );
}

/// Finds the menu label a route was given, so screens and the "unavailable"
/// fallback both use the tenant's wording rather than the developer's.
String menuLabelFor(WidgetRef ref, String route, {String? fallback}) {
  final MenuPayload? menu = ref.read(menuProvider).valueOrNull;
  if (menu == null) {
    return fallback ?? route;
  }

  for (final MenuItem leaf in menu.tree.expand((MenuItem i) => i.leaves)) {
    if (leaf.route == route) {
      return leaf.label;
    }
  }
  return fallback ?? route;
}

/// Opens a server route, recording it as recently visited.
///
/// Takes the [GoRouter] rather than a [BuildContext] on purpose: the caller is
/// the drawer, which closes itself before navigating, and looking the router up
/// from a context whose subtree has just been popped is how this becomes an
/// intermittent crash rather than a navigation.
void openMenuItem(GoRouter router, WidgetRef ref, MenuItem item) {
  final String? route = item.route;
  if (route == null) {
    return;
  }

  ref.read(menuProvider.notifier).trackRecent(item);
  router.go(route);
}

/// Sign-out with a confirmation.
///
/// Confirmed because on a shared counter handset it is one mis-tap away from the
/// menu, and signing back in means finding a password somebody else set.
Future<void> confirmSignOut(BuildContext context, WidgetRef ref) async {
  final AppStrings strings = AppStrings.of(context);

  final bool confirmed = await showDialog<bool>(
        context: context,
        builder: (BuildContext context) => AlertDialog(
          title: Text(strings.actionSignOut),
          content: Text(strings.authSignOutConfirm),
          actions: <Widget>[
            TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: Text(strings.actionCancel),
            ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(true),
              child: Text(strings.actionSignOut),
            ),
          ],
        ),
      ) ??
      false;

  if (confirmed) {
    await ref.read(sessionProvider.notifier).signOut();
  }
}
