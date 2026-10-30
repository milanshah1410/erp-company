import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_response.dart';
import '../../../core/providers.dart';
import '../../../core/tenant/tenant_context.dart';

/// Every authentication call, in one place (§4).
///
/// Note what is missing: there is no `refresh()`. This backend issues Sanctum
/// personal access tokens with `expiration => null` — they do not expire, so
/// there is nothing to refresh. A 401 therefore means *revoked* (signed out
/// elsewhere, password reset, token deleted), and the only correct response is
/// to sign in again. Inventing a refresh endpoint the server does not have would
/// be the kind of silent invention §30 forbids.
class AuthRepository {
  const AuthRepository(this._api);

  final ApiClient _api;

  /// `POST /api/login` → `{token, user}`.
  ///
  /// Bad credentials come back as **422**, not 401 — the controller throws a
  /// `ValidationException`. So the sign-in screen shows the failure under the
  /// email field like any other validation error, and [UnauthorizedException]
  /// stays meaning "the session ended".
  Future<LoginResult> login({
    required String email,
    required String password,
  }) async {
    final Response<dynamic> response = await _api.post(
      '/login',
      data: <String, dynamic>{'email': email, 'password': password},
    );

    // Not wrapped in `data` — login returns a bare payload. ApiResponse.unwrap
    // would pass it through untouched, but reading it directly is clearer about
    // which shape this endpoint actually uses.
    final Map<String, dynamic> body = response.data as Map<String, dynamic>;

    return LoginResult(
      token: body['token'] as String,
      context: TenantContext.fromJson(body['user'] as Map<String, dynamic>),
    );
  }

  /// `GET /api/me` — the session payload, re-read on every launch.
  ///
  /// Never cached to disk. Permissions and enabled modules can be changed by an
  /// admin or by the platform operator between two launches, and a cached copy
  /// would keep granting something that has been taken away.
  Future<TenantContext> me() async {
    final Response<dynamic> response = await _api.get('/me');
    return TenantContext.fromJson(response.data as Map<String, dynamic>);
  }

  /// `POST /api/logout` — revokes the current token server-side.
  Future<void> logout() => _api.post('/logout');

  /// `POST /api/forgot-password`.
  ///
  /// Always succeeds, whether or not the address exists — the API refuses to
  /// confirm membership. The UI must therefore show the same confirmation
  /// either way, and must not be "helpful" about unknown addresses.
  Future<String> forgotPassword(String email) async {
    final Response<dynamic> response = await _api.post(
      '/forgot-password',
      data: <String, dynamic>{'email': email},
    );
    return ApiResponse.message(response.data);
  }

  /// `POST /api/reset-password`. On success every token for the account is
  /// revoked server-side, so the user must sign in again — including on this
  /// device.
  Future<String> resetPassword({
    required String email,
    required String token,
    required String password,
  }) async {
    final Response<dynamic> response = await _api.post(
      '/reset-password',
      data: <String, dynamic>{
        'email': email,
        'token': token,
        'password': password,
        'password_confirmation': password,
      },
    );
    return ApiResponse.message(response.data);
  }

  /// `PUT /api/profile/password` for a signed-in user (§4 change password).
  Future<String> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    final Response<dynamic> response = await _api.put(
      '/profile/password',
      data: <String, dynamic>{
        'current_password': currentPassword,
        'password': newPassword,
        'password_confirmation': newPassword,
      },
    );
    return ApiResponse.message(response.data);
  }
}

class LoginResult {
  const LoginResult({required this.token, required this.context});

  final String token;
  final TenantContext context;
}

final Provider<AuthRepository> authRepositoryProvider =
    Provider<AuthRepository>(
  (Ref ref) => AuthRepository(ref.watch(apiClientProvider)),
);
