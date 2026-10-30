import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/config/app_config.dart';
import '../../../core/widgets/app_state_views.dart';
import '../../../l10n/generated/app_localizations.dart';

enum UnavailableReason {
  /// The workspace grants this screen, but this app build has no Flutter screen
  /// for it. Expected and permanent for most of the 319-leaf catalogue — the
  /// web app is the complete client, and the phone is the subset worth carrying.
  notInApp,

  /// The server listed this route in `denied_routes`: a module switched off, a
  /// screen the operator revoked, or a permission this user does not hold.
  denied,
}

/// What the app shows for a route it will not open.
///
/// The distinction between the two reasons matters, and collapsing them into one
/// "not available" message would be a small lie with real support cost:
///
/// * [UnavailableReason.notInApp] is *our* limitation — so it offers the web
///   version, which can do it.
/// * [UnavailableReason.denied] is the workspace's own configuration — so it
///   says so plainly, and offers nothing, because the web app would refuse the
///   same user for the same reason.
class ScreenUnavailable extends StatelessWidget {
  const ScreenUnavailable({
    required this.route,
    required this.reason,
    super.key,
    this.label,
  });

  final String route;
  final UnavailableReason reason;

  /// The workspace's own name for the screen, taken from the menu — tenants
  /// rename rows, and the rename is what their staff recognise.
  final String? label;

  @override
  Widget build(BuildContext context) {
    final AppStrings strings = AppStrings.of(context);
    final String name = label ?? route;

    return Scaffold(
      appBar: AppBar(title: Text(name)),
      body: switch (reason) {
        UnavailableReason.notInApp => AppEmptyState(
            icon: Icons.open_in_new,
            title: strings.screenUnavailableTitle,
            body: strings.screenUnavailableBody(name),
            action: OutlinedButton.icon(
              onPressed: () => _openOnWeb(route),
              icon: const Icon(Icons.language),
              label: Text(strings.actionOpenInBrowser),
            ),
          ),
        UnavailableReason.denied => AppEmptyState(
            icon: Icons.lock_outline,
            title: strings.screenDeniedTitle,
            body: strings.screenDeniedBody,
          ),
      },
    );
  }

  /// Opens the same screen in the web app.
  ///
  /// The web host is derived from the API base URL by dropping the `/api`
  /// suffix, which holds because both are served from the same origin. If that
  /// ever stops being true this needs its own `--dart-define`, not a guess.
  Future<void> _openOnWeb(String route) async {
    final String origin =
        AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/?$'), '');
    final Uri uri = Uri.parse('$origin$route');

    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }
}
