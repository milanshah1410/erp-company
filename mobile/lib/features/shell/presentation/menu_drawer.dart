import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/menu/menu_item.dart';
import '../../../core/menu/menu_repository.dart';
import '../../../core/routing/route_registry.dart';
import '../../../core/tenant/tenant_context.dart';
import '../../../core/widgets/app_state_views.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../../routes/screen_routes.dart';
import '../../auth/application/session_controller.dart';
import 'app_shell.dart';

/// The navigation drawer — a direct rendering of `GET /api/menu` (§6).
///
/// **Nothing in this file decides what appears in it.** No permission checks, no
/// module checks, no industry switch. The server has already applied all six
/// gates (permission, role, module, industry, plan, feature flag); duplicating
/// any of that here would create a second opinion that can drift from the one
/// the API actually enforces.
///
/// What the client *does* add is honesty about its own coverage: a row whose
/// screen this build does not have is shown dimmed, so a user can see it exists
/// and open it on the web, rather than tapping into a dead end.
class MenuDrawer extends ConsumerWidget {
  const MenuDrawer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<MenuPayload> menu = ref.watch(menuProvider);
    final TenantContext? tenant = ref.watch(tenantContextProvider);

    return Drawer(
      child: SafeArea(
        child: Column(
          children: <Widget>[
            if (tenant != null) _Header(tenant: tenant),
            Expanded(
              child: menu.when(
                loading: () => const AppLoading(),
                error: (Object e, _) => AppErrorState(
                  error: e,
                  onRetry: () => ref.read(menuProvider.notifier).refresh(),
                ),
                data: (MenuPayload payload) => _MenuList(payload: payload),
              ),
            ),
            const Divider(height: 1),
            const _Footer(),
          ],
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.tenant});

  final TenantContext tenant;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
      child: Row(
        children: <Widget>[
          CircleAvatar(
            radius: 22,
            backgroundImage: tenant.avatarUrl == null
                ? null
                : NetworkImage(tenant.avatarUrl!),
            child: tenant.avatarUrl == null
                ? Text(tenant.name.isEmpty ? '?' : tenant.name[0].toUpperCase())
                : null,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  tenant.name,
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
                Text(
                  // The company, not the tenant id — "Shah Footwear" is what
                  // the person holding the phone recognises.
                  tenant.currentCompany?.name ?? tenant.email,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MenuList extends ConsumerWidget {
  const _MenuList({required this.payload});

  final MenuPayload payload;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AppStrings strings = AppStrings.of(context);

    return ListView(
      padding: EdgeInsets.zero,
      children: <Widget>[
        if (payload.favorites.isNotEmpty) ...<Widget>[
          _SectionLabel(strings.navFavorites),
          ...payload.favorites.map(
            (MenuItem item) => _Leaf(item: item),
          ),
          const Divider(height: 1),
        ],
        ...payload.tree.map((MenuItem item) => _Node(item: item)),
      ],
    );
  }
}

/// A group or a leaf, recursively. The catalogue nests up to four deep
/// (Masters → Products → Barcode → Scan), so this cannot be a two-level list.
class _Node extends StatelessWidget {
  const _Node({required this.item, this.depth = 0});

  final MenuItem item;
  final int depth;

  @override
  Widget build(BuildContext context) {
    if (!item.isGroup) {
      return _Leaf(item: item, depth: depth);
    }

    return ExpansionTile(
      // Collapsed by default: a shop with twenty groups expanded is a scroll,
      // not a menu.
      initiallyExpanded: false,
      tilePadding: EdgeInsets.only(left: 16.0 + depth * 12, right: 16),
      leading: _iconFor(item.icon),
      title: Text(item.label),
      children: item.children
          .map((MenuItem child) => _Node(item: child, depth: depth + 1))
          .toList(),
    );
  }
}

class _Leaf extends ConsumerWidget {
  const _Leaf({required this.item, this.depth = 0});

  final MenuItem item;
  final int depth;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final String route = item.route ?? '';
    final RouteRegistry registry = ref.watch(routeRegistryProvider);
    final bool available = registry.isRegistered(route);
    final ThemeData theme = Theme.of(context);

    return ListTile(
      contentPadding: EdgeInsets.only(left: 16.0 + depth * 12, right: 8),
      leading: _iconFor(item.icon),
      title: Text(
        item.label,
        // Dimmed rather than hidden. The row is real — the workspace grants it,
        // and it works on the web — so removing it would misrepresent what the
        // user has. Dimming says "not here", which is the truth.
        style: available
            ? null
            : TextStyle(color: theme.colorScheme.onSurfaceVariant),
      ),
      trailing: available
          ? null
          : Icon(
              Icons.open_in_new,
              size: 16,
              color: theme.colorScheme.outline,
            ),
      onTap: () {
        // Resolved before the drawer closes — see openMenuItem.
        final GoRouter router = GoRouter.of(context);
        Navigator.of(context).pop();
        openMenuItem(router, ref, item);
      },
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.label);

  final String label;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
        child: Text(
          label.toUpperCase(),
          style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
                letterSpacing: 0.8,
              ),
        ),
      );
}

class _Footer extends ConsumerWidget {
  const _Footer();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AppStrings strings = AppStrings.of(context);

    return Column(
      children: <Widget>[
        ListTile(
          leading: const Icon(Icons.settings_outlined),
          title: Text(strings.navSettings),
          onTap: () {
            Navigator.of(context).pop();
            // Settings is app-owned (language, theme, devices) rather than a
            // catalogue screen, so it is not resolved through the registry.
          },
        ),
        ListTile(
          leading: const Icon(Icons.logout),
          title: Text(strings.actionSignOut),
          onTap: () async {
            Navigator.of(context).pop();
            await confirmSignOut(context, ref);
          },
        ),
      ],
    );
  }
}

/// Maps the server's PrimeIcons name onto a Material icon.
///
/// The catalogue stores icons as `pi pi-shopping-cart` — PrimeVue's set, chosen
/// for the web client. Rather than ship an icon font to match, a small mapping
/// covers the groups that actually appear, and anything unmapped falls back to a
/// neutral dot. A wrong-but-present icon would be worse than a plain one.
Widget? _iconFor(String? icon) {
  if (icon == null) {
    return null;
  }

  final String name = icon.replaceAll('pi pi-', '').replaceAll('pi-', '');

  return Icon(
    switch (name) {
      'home' || 'th-large' => Icons.dashboard_outlined,
      'shopping-cart' || 'shopping-bag' => Icons.shopping_cart_outlined,
      'box' || 'inbox' => Icons.inventory_2_outlined,
      'users' || 'user' => Icons.people_outline,
      'truck' => Icons.local_shipping_outlined,
      'wallet' || 'credit-card' || 'money-bill' => Icons.payments_outlined,
      'chart-bar' || 'chart-line' => Icons.bar_chart_outlined,
      'cog' || 'sliders-h' => Icons.settings_outlined,
      'tags' || 'tag' => Icons.sell_outlined,
      'building' || 'warehouse' => Icons.warehouse_outlined,
      'file' || 'file-edit' => Icons.description_outlined,
      'calendar' => Icons.calendar_today_outlined,
      'qrcode' || 'barcode' => Icons.qr_code_2,
      _ => Icons.circle_outlined,
    },
    size: 20,
  );
}
