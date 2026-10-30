import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/routing/route_registry.dart';
import '../features/auth/application/session_controller.dart';
import '../features/auth/presentation/forgot_password_screen.dart';
import '../features/auth/presentation/login_screen.dart';
import '../features/dashboard/presentation/dashboard_screen.dart';
import '../features/shell/presentation/app_shell.dart';
import '../features/shell/presentation/splash_screen.dart';
import '../features/shell/presentation/suspended_screen.dart';
import 'screen_routes.dart';

/// Routing (§6).
///
/// Two ideas do the work here:
///
/// 1. **A redirect drives the session.** Whether the app shows a splash, the
///    sign-in screen, the suspended screen or the app is decided in one function
///    from one piece of state. Screens never navigate on session changes
///    themselves, which is what stops two of them disagreeing and pushing
///    competing routes.
///
/// 2. **Business screens are resolved dynamically.** They are not declared here
///    one by one. A single catch-all route asks [RouteRegistry] for whatever the
///    server's menu said, so adding a screen means adding a registry entry — and
///    an industry pack means adding several — with nothing in this file to
///    change.
final Provider<GoRouter> routerProvider = Provider<GoRouter>((Ref ref) {
  // A listenable that fires when the session changes, so go_router re-evaluates
  // the redirect below. Riverpod's own listener rather than a ChangeNotifier:
  // one source of truth, and no second object to keep in step.
  final _SessionRefresh refresh = _SessionRefresh(ref);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: AppRoutes.splash,
    refreshListenable: refresh,
    routes: <RouteBase>[
      GoRoute(
        path: AppRoutes.splash,
        builder: (_, __) => const SplashScreen(),
      ),
      GoRoute(
        path: AppRoutes.login,
        builder: (_, __) => const LoginScreen(),
      ),
      GoRoute(
        path: AppRoutes.forgotPassword,
        builder: (_, __) => const ForgotPasswordScreen(),
      ),
      GoRoute(
        path: AppRoutes.suspended,
        builder: (_, __) => const SuspendedScreen(),
      ),

      /// The signed-in app. Everything inside shares the drawer and app bar
      /// rather than each screen rebuilding them.
      ShellRoute(
        builder: (_, __, Widget child) => AppShell(child: child),
        routes: <RouteBase>[
          GoRoute(
            path: AppRoutes.dashboard,
            builder: (_, __) => const DashboardScreen(),
          ),

          /// The catch-all that makes the menu dynamic.
          ///
          /// Anything the server offered lands here and is resolved against the
          /// registry at navigation time. Three outcomes, and all three are
          /// deliberate:
          ///
          /// * registered → the screen;
          /// * granted but not built yet → [ScreenUnavailable], which offers the
          ///   web version rather than a dead end;
          /// * in `denied_routes` → refused, because hiding a menu row is not
          ///   the same as closing the door. A bookmark, a stale favourite, a
          ///   notification tap or a typed URL all arrive here.
          GoRoute(
            path: '/:screen(.*)',
            builder: (BuildContext context, GoRouterState state) {
              final String route = '/${state.pathParameters['screen'] ?? ''}';
              return DynamicScreen(route: route);
            },
          ),
        ],
      ),
    ],

    redirect: (BuildContext context, GoRouterState state) {
      final AsyncValue<SessionState> session = ref.read(sessionProvider);
      final String location = state.matchedLocation;

      // Still resolving /me — hold on the splash rather than flashing sign-in
      // at a user who is already signed in.
      if (session.isLoading || session.valueOrNull == null) {
        return location == AppRoutes.splash ? null : AppRoutes.splash;
      }

      final bool onAuthScreen = location == AppRoutes.login ||
          location == AppRoutes.forgotPassword;

      return switch (session.value!) {
        SessionLoading() => AppRoutes.splash,

        // The token is kept on purpose — the user must still be able to sign
        // out from here. See WorkspaceSuspended.
        WorkspaceSuspended() =>
          location == AppRoutes.suspended ? null : AppRoutes.suspended,

        SignedOut() => onAuthScreen ? null : AppRoutes.login,

        // Signed in: bounce off the auth screens and the splash, and let every
        // other location through to the registry.
        SignedIn() => (onAuthScreen ||
                location == AppRoutes.splash ||
                location == AppRoutes.suspended)
            ? AppRoutes.dashboard
            : null,
      };
    },
  );
});

/// Bridges Riverpod's session state to go_router's [Listenable].
class _SessionRefresh extends ChangeNotifier {
  _SessionRefresh(Ref ref) {
    _subscription = ref.listen<AsyncValue<SessionState>>(
      sessionProvider,
      (_, __) => notifyListeners(),
      fireImmediately: false,
    );
  }

  late final ProviderSubscription<AsyncValue<SessionState>> _subscription;

  @override
  void dispose() {
    _subscription.close();
    super.dispose();
  }
}
