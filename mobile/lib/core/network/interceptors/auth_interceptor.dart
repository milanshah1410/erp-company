import 'package:dio/dio.dart';

/// Attaches the two headers every authenticated call needs.
///
/// **Note what is absent: there is no tenant header.** The Laravel side resolves
/// the workspace from the bearer token (`ResolveTenant` reads `tenant_id` off the
/// authenticated user), so the token *is* the tenant. §5's "never allow users to
/// manually switch tenant_id" is satisfied structurally — there is no parameter
/// to tamper with, and adding one would be inventing an attack surface the
/// backend does not have.
class AuthInterceptor extends Interceptor {
  AuthInterceptor({required this.tokenReader, required this.localeReader});

  /// Read lazily on every request rather than captured once: the token changes
  /// at sign-in, sign-out and password reset, and a captured copy would keep
  /// sending a revoked one.
  final Future<String?> Function() tokenReader;

  /// The UI language. Sent so the API returns validation messages and business
  /// errors already translated (§28) — the alternative is an English error
  /// inside a Gujarati screen.
  final String Function() localeReader;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final String? token = await tokenReader();
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }

    options.headers['Accept'] = 'application/json';
    options.headers['X-Locale'] = localeReader();

    handler.next(options);
  }
}
