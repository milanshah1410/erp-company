import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../l10n/generated/app_localizations.dart';
import '../../auth/application/session_controller.dart';

/// The workspace has been hard-locked by the platform operator.
///
/// **Sign out is the only action, and it must always be here.** The token is
/// deliberately kept when the app reaches this screen (see [WorkspaceSuspended])
/// so the user is not trapped: without a way out they would have to reinstall
/// the app to sign in as somebody else.
///
/// No retry button. Suspension ends when an operator ends it, and a button that
/// re-checks would only teach people to keep pressing it.
class SuspendedScreen extends ConsumerWidget {
  const SuspendedScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AppStrings strings = AppStrings.of(context);
    final ThemeData theme = Theme.of(context);

    final String message = switch (ref.watch(sessionProvider).valueOrNull) {
      final WorkspaceSuspended s => s.message,
      _ => strings.tenantSuspendedBody,
    };

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Icon(
                Icons.pause_circle_outline,
                size: 56,
                color: theme.colorScheme.error,
              ),
              const SizedBox(height: 20),
              Text(
                strings.tenantSuspendedTitle,
                style: theme.textTheme.headlineSmall,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              Text(
                // The server's own wording where it gave us one: it explains
                // *why*, which a generic string cannot.
                message,
                style: theme.textTheme.bodyMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 32),
              OutlinedButton.icon(
                onPressed: () =>
                    ref.read(sessionProvider.notifier).signOut(),
                icon: const Icon(Icons.logout),
                label: Text(strings.actionSignOut),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
