import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/tenant/tenant_context.dart';
import '../../../core/widgets/app_state_views.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/application/session_controller.dart';

/// The landing screen.
///
/// **Phase 2 scope only.** It confirms the session, tenant context and menu are
/// wired end to end, and shows the workspace the user is actually in. The KPI
/// widgets (§7) are Phase 3, and they will come from `GET /api/dashboard/kpis`
/// plus the saved layout at `GET /api/dashboard/layout` — both already exist
/// server-side, so nothing here is hardcoded now and nothing will be then.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AppStrings strings = AppStrings.of(context);
    final TenantContext? tenant = ref.watch(tenantContextProvider);

    if (tenant == null) {
      return const Scaffold(body: AppLoading());
    }

    return Scaffold(
      appBar: AppBar(title: Text(strings.navDashboard)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    tenant.currentCompany?.name ?? tenant.name,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  if (tenant.currentBranch != null) ...<Widget>[
                    const SizedBox(height: 4),
                    Text(tenant.currentBranch!.name),
                  ],
                  const SizedBox(height: 12),
                  // What the *server* decided this user is, rendered as-is.
                  // Useful during Phase 2 to confirm gating end to end, and
                  // genuinely useful afterwards on a support call.
                  _Row(label: 'Signed in as', value: tenant.name),
                  _Row(label: 'Roles', value: tenant.roles.join(', ')),
                  _Row(
                    label: 'Permissions',
                    value: '${tenant.permissions.length} granted',
                  ),
                  _Row(
                    label: 'Modules',
                    value: tenant.modules == null
                        ? 'all (no module gating)'
                        : '${tenant.modules!.length} enabled',
                  ),
                  _Row(label: 'Currency', value: tenant.currencyCode),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final ThemeData theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          SizedBox(
            width: 120,
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
        ],
      ),
    );
  }
}
