import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../errors/api_exception.dart';
import 'interceptors/auth_interceptor.dart';
import 'interceptors/error_interceptor.dart';
import 'interceptors/logging_interceptor.dart';
import 'interceptors/retry_interceptor.dart';

/// The one way this app talks to Laravel (§3).
///
/// Repositories call these methods and catch [ApiException]. Nothing above this
/// class imports Dio, which is what keeps the HTTP library a replaceable detail
/// rather than a dependency threaded through 200 files.
///
/// Interceptor order is deliberate and load-bearing:
///
/// 1. [AuthInterceptor] — headers first, so everything below sees the real
///    request that will go out.
/// 2. [RetryInterceptor] — before error mapping, so a retried request that
///    succeeds never becomes a user-visible error at all.
/// 3. [ErrorInterceptor] — last, converting whatever survived into an
///    [ApiException].
/// 4. [LoggingInterceptor] — debug builds only.
class ApiClient {
  ApiClient({
    required Future<String?> Function() tokenReader,
    required String Function() localeReader,
    required Future<void> Function() onUnauthorized,
    Dio? dio,
  }) : _dio = dio ?? Dio() {
    _dio.options = _dio.options.copyWith(
      baseUrl: AppConfig.apiBaseUrl,
      connectTimeout: AppConfig.connectTimeout,
      receiveTimeout: AppConfig.receiveTimeout,
      sendTimeout: AppConfig.sendTimeout,
      // We map status codes ourselves in ErrorInterceptor; letting Dio throw on
      // 4xx first would mean handling failures in two places.
      validateStatus: (int? status) => status != null && status < 400,
      headers: <String, dynamic>{'Accept': 'application/json'},
    );

    _dio.interceptors.addAll(<Interceptor>[
      AuthInterceptor(tokenReader: tokenReader, localeReader: localeReader),
      RetryInterceptor(dio: _dio),
      ErrorInterceptor(onUnauthorized: onUnauthorized),
      if (AppConfig.enableNetworkLogging) LoggingInterceptor(),
    ]);
  }

  final Dio _dio;

  /// Escape hatch for the few things that need the raw client — multipart
  /// uploads with progress, and file downloads. Not for ordinary calls.
  Dio get raw => _dio;

  Future<Response<dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) =>
      _send(
        () => _dio.get<dynamic>(
          path,
          queryParameters: _clean(query),
          cancelToken: cancelToken,
        ),
      );

  /// [options] is where an idempotency key goes — see `idempotent()` in
  /// `idempotency.dart`. Every write that costs money or moves stock should
  /// carry one.
  Future<Response<dynamic>> post(
    String path, {
    Object? data,
    Map<String, dynamic>? query,
    Options? options,
    CancelToken? cancelToken,
  }) =>
      _send(
        () => _dio.post<dynamic>(
          path,
          data: data,
          queryParameters: _clean(query),
          options: options,
          cancelToken: cancelToken,
        ),
      );

  Future<Response<dynamic>> put(
    String path, {
    Object? data,
    Options? options,
    CancelToken? cancelToken,
  }) =>
      _send(
        () => _dio.put<dynamic>(
          path,
          data: data,
          options: options,
          cancelToken: cancelToken,
        ),
      );

  Future<Response<dynamic>> patch(
    String path, {
    Object? data,
    Options? options,
    CancelToken? cancelToken,
  }) =>
      _send(
        () => _dio.patch<dynamic>(
          path,
          data: data,
          options: options,
          cancelToken: cancelToken,
        ),
      );

  Future<Response<dynamic>> delete(
    String path, {
    Object? data,
    CancelToken? cancelToken,
  }) =>
      _send(
        () => _dio.delete<dynamic>(path, data: data, cancelToken: cancelToken),
      );

  /// Multipart upload (§20 product images, receipts, documents, PoD).
  ///
  /// Laravel cannot read a PUT/PATCH multipart body, so an update that carries a
  /// file must be POSTed with `_method` — the same spoofing the web client does.
  /// Encoding that here means no caller has to rediscover it.
  Future<Response<dynamic>> upload(
    String path, {
    required FormData form,
    String method = 'POST',
    Options? options,
    void Function(int sent, int total)? onProgress,
    CancelToken? cancelToken,
  }) {
    if (method.toUpperCase() != 'POST') {
      form.fields.add(MapEntry<String, String>('_method', method.toUpperCase()));
    }

    return _send(
      () => _dio.post<dynamic>(
        path,
        data: form,
        options: options,
        onSendProgress: onProgress,
        cancelToken: cancelToken,
      ),
    );
  }

  /// Unwraps the [ApiException] that [ErrorInterceptor] attached.
  ///
  /// By the time a failure reaches here it has already been classified, so this
  /// is a rethrow rather than a second mapping — two places deciding what a 403
  /// means is how they come to disagree.
  Future<Response<dynamic>> _send(
    Future<Response<dynamic>> Function() request,
  ) async {
    try {
      return await request();
    } on DioException catch (e) {
      final Object? mapped = e.error;
      if (mapped is ApiException) {
        throw mapped;
      }
      throw UnexpectedException(
        e.message ?? 'Something went wrong. Please try again.',
        cause: e,
      );
    }
  }

  /// Drops null and empty query values.
  ///
  /// Laravel's `$request->filled()` treats `?category_id=` as present-but-blank
  /// and several controllers then filter on an empty string, quietly returning
  /// nothing. Stripping them here means a cleared filter chip behaves like no
  /// filter, which is what the user meant by clearing it.
  Map<String, dynamic>? _clean(Map<String, dynamic>? query) {
    if (query == null) {
      return null;
    }

    final Map<String, dynamic> cleaned = <String, dynamic>{
      for (final MapEntry<String, dynamic> e in query.entries)
        if (e.value != null && e.value != '') e.key: e.value,
    };

    return cleaned.isEmpty ? null : cleaned;
  }
}
