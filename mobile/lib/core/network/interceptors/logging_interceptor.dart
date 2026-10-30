import 'dart:developer' as developer;

import 'package:dio/dio.dart';

/// Development-only request logging (§3, §25).
///
/// Two rules it must never break, because both leak straight into logcat where
/// any app on the device can read them:
///
/// 1. **Never installed in a release build.** [ApiClient] adds this only when
///    `AppConfig.enableNetworkLogging` is true, which requires both a debug
///    build and a non-production APP_ENV.
/// 2. **Never prints credentials.** The Authorization header and the password
///    fields are redacted here as well, so that a mistake in rule 1 is still not
///    a leaked bearer token.
class LoggingInterceptor extends Interceptor {
  static const Set<String> _redactedHeaders = <String>{
    'authorization',
    'cookie',
    'set-cookie',
  };

  static const Set<String> _redactedFields = <String>{
    'password',
    'password_confirmation',
    'current_password',
    'token',
    'secret',
    'api_key',
  };

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    developer.log(
      '→ ${options.method} ${options.uri}\n'
      '  headers: ${_scrubHeaders(options.headers)}\n'
      '  body: ${_scrubBody(options.data)}',
      name: 'api',
    );
    handler.next(options);
  }

  @override
  void onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) {
    developer.log(
      '← ${response.statusCode} ${response.requestOptions.uri}',
      name: 'api',
    );
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    developer.log(
      '✖ ${err.response?.statusCode ?? err.type.name} '
      '${err.requestOptions.method} ${err.requestOptions.uri}\n'
      '  ${_scrubBody(err.response?.data)}',
      name: 'api',
    );
    handler.next(err);
  }

  Map<String, dynamic> _scrubHeaders(Map<String, dynamic> headers) =>
      headers.map(
        (String key, dynamic value) => MapEntry<String, dynamic>(
          key,
          _redactedHeaders.contains(key.toLowerCase()) ? '<redacted>' : value,
        ),
      );

  Object? _scrubBody(Object? body) {
    if (body is Map) {
      return body.map(
        (Object? key, Object? value) => MapEntry<Object?, Object?>(
          key,
          _redactedFields.contains(key.toString().toLowerCase())
              ? '<redacted>'
              : _scrubBody(value),
        ),
      );
    }
    if (body is List) {
      return body.map(_scrubBody).toList();
    }
    return body;
  }
}
