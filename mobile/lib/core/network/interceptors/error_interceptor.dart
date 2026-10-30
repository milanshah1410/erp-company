import 'package:dio/dio.dart';

import '../../errors/api_exception.dart';

/// Turns transport failures into the app's [ApiException] taxonomy (§4).
///
/// This is the only place in the app that reads an HTTP status code. Everything
/// above it switches on a type, which is both exhaustive and impossible to get
/// subtly wrong — nobody has to remember that this backend answers bad login
/// credentials with 422 rather than 401.
class ErrorInterceptor extends Interceptor {
  ErrorInterceptor({required this.onUnauthorized});

  /// Called when a token stops working, so the session can be torn down once,
  /// centrally, instead of at every call site that might see a 401.
  final Future<void> Function() onUnauthorized;

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final ApiException mapped = await _map(err);

    handler.reject(
      DioException(
        requestOptions: err.requestOptions,
        response: err.response,
        type: err.type,
        error: mapped,
        message: mapped.message,
      ),
    );
  }

  Future<ApiException> _map(DioException err) async {
    switch (err.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      // Raised when *decoding* a response outruns the transformer's budget —
      // a huge report body on a slow handset, typically. Grouped with the other
      // timeouts because it means the same thing to a caller: nothing usable
      // arrived, and trying again is reasonable.
      case DioExceptionType.transformTimeout:
        return const NetworkException(
          'The server took too long to respond. Check your connection and try again.',
          isTimeout: true,
        );
      case DioExceptionType.connectionError:
        return const NetworkException(
          'No internet connection. Your work will be saved and sent when you are back online.',
        );
      case DioExceptionType.cancel:
        return const NetworkException('Request cancelled.');
      case DioExceptionType.badCertificate:
        // §25 certificate validation. Deliberately not recoverable and
        // deliberately not offered as "continue anyway": on a phone that prompt
        // is answered "yes" by everyone, which is the same as having no TLS.
        return const UnexpectedException(
          'Could not establish a secure connection to the server.',
        );
      case DioExceptionType.badResponse:
      case DioExceptionType.unknown:
        return _fromResponse(err);
    }
  }

  Future<ApiException> _fromResponse(DioException err) async {
    final Response<dynamic>? response = err.response;
    if (response == null) {
      return UnexpectedException(
        err.message ?? 'Something went wrong. Please try again.',
        cause: err.error,
      );
    }

    final Map<String, dynamic> body = switch (response.data) {
      final Map<String, dynamic> map => map,
      _ => const <String, dynamic>{},
    };

    final String message =
        (body['message'] as String?) ?? 'Something went wrong.';
    final String? code = body['error'] as String?;
    final int status = response.statusCode ?? 0;

    switch (status) {
      case 401:
        await onUnauthorized();
        return UnauthorizedException(
          body['message'] as String? ?? 'Your session has ended. Please sign in again.',
        );

      case 403:
        return ForbiddenException(message, code: code);

      case 409:
        // Only ever emitted by the idempotency middleware: an identical request
        // is mid-flight. Its own type, because the sync queue waits and re-sends
        // the same key rather than treating it as a failure.
        return RequestInProgressException(
          message,
          retryAfter: _retryAfter(response),
        );

      case 422:
        return ValidationException(message, _errors(body));

      case 429:
        return RateLimitException(
          'Too many requests. Please wait a moment and try again.',
          retryAfter: _retryAfter(response),
        );

      default:
        if (status >= 500) {
          return ServerException(
            'The server ran into a problem. Please try again shortly.',
            statusCode: status,
          );
        }
        return UnexpectedException(message);
    }
  }

  /// Laravel's `{errors: {field: [msg, ...]}}`, normalised.
  ///
  /// Values arrive as a list almost always and as a bare string occasionally
  /// (a hand-written `response()->json`), and a form that crashes on the second
  /// shape is a form that crashes on exactly one endpoint, months later.
  static Map<String, List<String>> _errors(Map<String, dynamic> body) {
    final dynamic raw = body['errors'];
    if (raw is! Map<String, dynamic>) {
      return const <String, List<String>>{};
    }

    return raw.map(
      (String field, dynamic messages) => MapEntry<String, List<String>>(
        field,
        switch (messages) {
          final List<dynamic> list =>
            list.map((dynamic m) => m.toString()).toList(),
          final String single => <String>[single],
          _ => <String>[messages.toString()],
        },
      ),
    );
  }

  static Duration? _retryAfter(Response<dynamic> response) {
    final String? header = response.headers.value('Retry-After');
    final int? seconds = header == null ? null : int.tryParse(header);
    return seconds == null ? null : Duration(seconds: seconds);
  }
}
