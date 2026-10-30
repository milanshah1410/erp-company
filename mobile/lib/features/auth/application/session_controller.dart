import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/auth/token_store.dart';
import '../../../core/errors/api_exception.dart';
import '../../../core/tenant/tenant_context.dart';
import '../data/auth_repository.dart';

/// Where the app is, session-wise. The router reads this and nothing else.
sealed class SessionState {
  const SessionState();
}

/// Before the first `/me` has resolved — the splash screen.
class SessionLoading extends SessionState {
  const SessionLoading();
}

class SignedOut extends SessionState {
  const SignedOut({this.reason});

  /// Set when the session ended by itself (token revoked), so the sign-in screen
  /// can say why rather than appearing for no visible reason.
  final String? reason;
}

class SignedIn extends SessionState {
  const SignedIn(this.context);

  final TenantContext context;
}

/// The workspace is hard-locked by the platform operator.
///
/// Its own state, not a variant of signed-out, because the token is deliberately
/// **kept**: the user must still be able to sign out or reach billing from the
/// suspended screen. Discarding the token would strand them there.
class WorkspaceSuspended extends SessionState {
  const WorkspaceSuspended(this.message);

  final String message;
}

/// Owns the session (§4, §5).
///
/// One rule runs through this class: the server is the authority on who you are
/// and what you may do. Sign-in stores a token; everything else — permissions,
/// modules, company, branch — is read from `/me` and never persisted, so an
/// admin revoking a permission takes effect on the next launch rather than
/// whenever a cache happens to expire.
class SessionController extends AsyncNotifier<SessionState> {
  AuthRepository get _repo => ref.read(authRepositoryProvider);
  TokenStore get _tokens => ref.read(tokenStoreProvider.notifier);

  @override
  Future<SessionState> build() async {
    // main() has already restored the token from the keychain, so this reads
    // memory rather than making the launch wait on a platform channel.
    final String? token = ref.watch(tokenStoreProvider);

    if (token == null || token.isEmpty) {
      return const SignedOut();
    }

    return _loadContext();
  }

  Future<SessionState> _loadContext() async {
    try {
      return SignedIn(await _repo.me());
    } on ForbiddenException catch (e) {
      // Suspension is two-tier on this platform. An operator suspension
      // hard-locks the workspace and is what this branch catches; a *billing*
      // suspension still allows sign-in (read-only, so the customer can pay),
      // and the API does not send `tenant_suspended` for it.
      if (e.isTenantSuspended) {
        return WorkspaceSuspended(e.message);
      }
      rethrow;
    } on UnauthorizedException {
      // The token was revoked while the app was closed — signed out from
      // another device, or a password reset. ErrorInterceptor has already
      // cleared it.
      return const SignedOut(reason: 'Your session has ended. Please sign in again.');
    }
  }

  /// Sign in. Errors propagate to the screen, which needs the typed
  /// [ValidationException] to put "credentials are incorrect" under the email
  /// field — so this deliberately does not swallow them into an error state.
  Future<void> signIn({required String email, required String password}) async {
    state = const AsyncValue<SessionState>.loading();

    try {
      final LoginResult result =
          await _repo.login(email: email, password: password);

      // Saving the token is the *only* thing this does, and that is deliberate.
      //
      // build() watches the token store, so writing it re-runs build and
      // resolves the session there. Setting `state` here as well would race that
      // rebuild — two writers, and the later one wins by timing rather than by
      // intent.
      //
      // The cost is one /me call whose answer login already gave us. That is a
      // fair price for having exactly one place where a session comes into
      // existence — and it is the same path a cold launch takes, so that path
      // gets exercised constantly instead of only on the rare route.
      await _tokens.save(result.token);
    } catch (e) {
      // Back to signed-out so the router does not strand the app on a spinner,
      // then rethrow for the form to render the field errors.
      state = const AsyncValue<SessionState>.data(SignedOut());
      rethrow;
    }
  }

  /// Sign out.
  ///
  /// The local token is cleared **whatever the server says**. A user who taps
  /// sign out on a train with no signal must still end up signed out; failing to
  /// revoke server-side leaves a token that expires on its own terms, which is a
  /// far smaller problem than a phone that stays logged in because a request
  /// failed.
  Future<void> signOut() async {
    try {
      await _repo.logout();
    } on ApiException {
      // Deliberately ignored — see above.
    } finally {
      await _tokens.clear();
      state = const AsyncValue<SessionState>.data(SignedOut());
    }
  }

  /// Re-read `/me` — after a company/branch switch, or when returning from the
  /// background after a long gap.
  Future<void> refresh() async {
    state = await AsyncValue.guard(_loadContext);
  }

  /// The signed-in context, or null. The convenience every screen wants.
  TenantContext? get contextOrNull => switch (state.valueOrNull) {
        final SignedIn s => s.context,
        _ => null,
      };
}

final AsyncNotifierProvider<SessionController, SessionState> sessionProvider =
    AsyncNotifierProvider<SessionController, SessionState>(
  SessionController.new,
);

/// The current [TenantContext], or null when signed out.
///
/// A separate provider so a widget that only cares about permissions does not
/// rebuild every time the session's async status changes.
final Provider<TenantContext?> tenantContextProvider =
    Provider<TenantContext?>((Ref ref) {
  final SessionState? session = ref.watch(sessionProvider).valueOrNull;
  return switch (session) {
    final SignedIn s => s.context,
    _ => null,
  };
});

/// Permission check for widgets (§25 — UX only; Laravel still decides).
///
/// Reads false when signed out, so a widget built during sign-out does not
/// briefly flash controls it should not offer.
final ProviderFamily<bool, String> canProvider =
    Provider.family<bool, String>((Ref ref, String permission) {
  return ref.watch(tenantContextProvider)?.can(permission) ?? false;
});
