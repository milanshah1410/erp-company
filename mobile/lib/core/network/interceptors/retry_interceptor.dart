import 'package:dio/dio.dart';

import '../../config/app_config.dart';
import '../idempotency.dart';

/// Retries requests that are safe to retry — and only those (§3 "retry where
/// appropriate").
///
/// The rule is not "retry on failure". It is:
///
/// * **Reads** (GET/HEAD) retry freely. Repeating them changes nothing.
/// * **Writes** retry only when they carry an `Idempotency-Key`. The backend
///   then recognises the second arrival as the same business intent and replays
///   the first reply instead of posting a second bill (see
///   `EnsureIdempotency` on the Laravel side).
/// * **A write with no key is never retried**, however tempting the timeout
///   looks. A request that timed out may well have succeeded — the reply is what
///   went missing — and re-sending it is how a shop ends up billing a customer
///   twice.
///
/// Only transport failures retry. A 4xx is a considered refusal and will be
/// refused identically next time; a 5xx means the server *received* the request,
/// so for writes it falls under the same doubt as a timeout.
class RetryInterceptor extends Interceptor {
  RetryInterceptor({required this.dio, this.maxRetries = AppConfig.maxRetries});

  final Dio dio;
  final int maxRetries;

  static const String _attemptKey = '_retryAttempt';

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final RequestOptions options = err.requestOptions;
    final int attempt = (options.extra[_attemptKey] as int?) ?? 0;

    if (attempt >= maxRetries || !_isRetryable(err)) {
      handler.next(err);
      return;
    }

    // Linear rather than exponential: two attempts a second apart is the whole
    // budget here, and a counter waiting eight seconds to be told it failed is
    // worse for the person at the till than being told promptly.
    await Future<void>.delayed(Duration(milliseconds: 400 * (attempt + 1)));

    options.extra[_attemptKey] = attempt + 1;

    try {
      final Response<dynamic> response = await dio.fetch<dynamic>(options);
      handler.resolve(response);
    } on DioException catch (e) {
      handler.next(e);
    }
  }

  bool _isRetryable(DioException err) {
    final bool transportFailure = switch (err.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.sendTimeout ||
      DioExceptionType.receiveTimeout ||
      DioExceptionType.connectionError =>
        true,
      _ => false,
    };

    if (!transportFailure) {
      return false;
    }

    final String method = err.requestOptions.method.toUpperCase();
    if (method == 'GET' || method == 'HEAD') {
      return true;
    }

    // A write is retryable exactly when it named itself, so the server can
    // recognise the repeat.
    return err.requestOptions.headers.containsKey(kIdempotencyKeyHeader);
  }
}
