import 'package:flutter/foundation.dart';

/// Build-time configuration.
///
/// Everything here arrives via `--dart-define`, never from a checked-in file
/// (§25 "no secrets in source code"). There is nothing secret in this class
/// today and there must not be tomorrow either: an API key compiled into an APK
/// is a published API key, so anything sensitive stays behind the Laravel API
/// (§23 WhatsApp, §24 AI) and the app only ever asks for an action.
///
/// Run against a local WAMP backend:
///
/// ```sh
/// flutter run \
///   --dart-define=API_BASE_URL=http://10.0.2.2/api \
///   --dart-define=APP_ENV=development
/// ```
///
/// `10.0.2.2` rather than `localhost`: on the Android emulator, localhost is the
/// handset. This is the single most common wasted afternoon on a project like
/// this one, which is why it is written down here.
class AppConfig {
  const AppConfig._();

  /// Root of the Laravel API, including the `/api` prefix.
  ///
  /// One host for every tenant. The workspace subdomain a customer signs up with
  /// is an identity string in the platform panel, not a routable address — the
  /// bearer token is what selects the tenant. Do **not** build per-tenant base
  /// URLs (see `docs/API_CONTRACT.md` §Tenancy).
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2/api',
  );

  static const String appEnv = String.fromEnvironment(
    'APP_ENV',
    defaultValue: 'development',
  );

  static bool get isProduction => appEnv == 'production';

  /// §3 "logging only in development", §25 "no production API debugging logs".
  ///
  /// Two conditions, not one: `kDebugMode` alone would still log from a profile
  /// build, and APP_ENV alone would let a misconfigured release build log a
  /// bearer token to logcat.
  static bool get enableNetworkLogging => kDebugMode && !isProduction;

  static const Duration connectTimeout = Duration(seconds: 15);

  /// Longer than connect: some reports and PDF endpoints legitimately take a
  /// while, and a counter on a 3G connection is not a broken counter.
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration sendTimeout = Duration(seconds: 30);

  /// How many times a *safe* request is retried before surfacing an error.
  /// Applies to reads, and to writes only when they carry an Idempotency-Key —
  /// see [RetryInterceptor].
  static const int maxRetries = 2;

  /// Page size asked for by list screens. The API caps `per_page` at 100.
  static const int defaultPageSize = 20;
}
