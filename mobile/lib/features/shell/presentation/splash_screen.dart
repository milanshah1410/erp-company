import 'package:flutter/material.dart';

import '../../../core/widgets/app_state_views.dart';

/// Shown while `/me` resolves at launch.
///
/// Exists to prevent a specific, ugly flash: without it, a signed-in user sees
/// the sign-in screen for a moment before the session check completes and
/// replaces it. Deliberately plain — a splash that is animated is a splash
/// somebody is tempted to hold on screen.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) =>
      const Scaffold(body: AppLoading());
}
