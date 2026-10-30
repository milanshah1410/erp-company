import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http_mock_adapter/http_mock_adapter.dart';
import 'package:kosha_mobile/core/errors/api_exception.dart';
import 'package:kosha_mobile/core/network/api_client.dart';

/// §4's error table, asserted against the codes this backend actually sends.
///
/// The one that always surprises people is the first test: **bad credentials
/// come back 422, not 401**, because `AuthController@login` throws a
/// `ValidationException`. A client that treats 401 as "wrong password" would
/// show the wrong message and, worse, would not clear the session on a genuinely
/// revoked token.
void main() {
  late Dio dio;
  late DioAdapter adapter;
  late ApiClient api;
  late int unauthorizedCalls;

  setUp(() {
    dio = Dio(BaseOptions(baseUrl: 'http://test/api'));
    adapter = DioAdapter(dio: dio);
    unauthorizedCalls = 0;

    api = ApiClient(
      dio: dio,
      tokenReader: () async => 'token',
      localeReader: () => 'gu',
      onUnauthorized: () async => unauthorizedCalls++,
    );
  });

  test('422 becomes a ValidationException with per-field messages', () async {
    adapter.onPost(
      '/login',
      (server) => server.reply(422, <String, dynamic>{
        'message': 'The given data was invalid.',
        'errors': <String, dynamic>{
          'email': <String>['The provided credentials are incorrect.'],
        },
      }),
      data: <String, dynamic>{'email': 'a@b.test', 'password': 'wrong'},
    );

    await expectLater(
      api.post(
        '/login',
        data: <String, dynamic>{'email': 'a@b.test', 'password': 'wrong'},
      ),
      throwsA(
        isA<ValidationException>().having(
          (ValidationException e) => e.forField('email'),
          'email error',
          'The provided credentials are incorrect.',
        ),
      ),
    );

    expect(unauthorizedCalls, 0, reason: 'a 422 must not end the session');
  });

  test('401 ends the session exactly once, centrally', () async {
    adapter.onGet(
      '/me',
      (server) =>
          server.reply(401, <String, dynamic>{'message': 'Unauthenticated.'}),
    );

    await expectLater(api.get('/me'), throwsA(isA<UnauthorizedException>()));
    expect(unauthorizedCalls, 1);
  });

  test('403 carries the typed reason that decides where the app goes', () async {
    adapter.onGet(
      '/products',
      (server) => server.reply(403, <String, dynamic>{
        'message': 'This workspace has been suspended.',
        'error': 'tenant_suspended',
      }),
    );

    await expectLater(
      api.get('/products'),
      throwsA(
        isA<ForbiddenException>()
            .having((ForbiddenException e) => e.isTenantSuspended, 'suspended', isTrue),
      ),
    );
  });

  test('403 module_disabled is distinguishable from suspension', () async {
    adapter.onGet(
      '/manufacturing-orders',
      (server) => server.reply(403, <String, dynamic>{
        'message': 'The Manufacturing module is not enabled for this workspace.',
        'error': 'module_disabled',
      }),
    );

    await expectLater(
      api.get('/manufacturing-orders'),
      throwsA(
        isA<ForbiddenException>()
            .having((ForbiddenException e) => e.isModuleDisabled, 'module', isTrue)
            .having((ForbiddenException e) => e.isTenantSuspended, 'suspended', isFalse),
      ),
    );
  });

  test('409 idempotency_in_progress is its own type, not a failure', () async {
    adapter.onPost(
      '/pos/checkout',
      (server) => server.reply(
        409,
        <String, dynamic>{
          'message': 'An identical request is still being processed.',
          'error': 'idempotency_in_progress',
        },
        // The content type must be repeated, not just added: `reply()`'s
        // `headers` argument *replaces* its default of
        // {content-type: application/json}, and without it the adapter stops
        // encoding the body as JSON — which surfaces as a confusing
        // UnexpectedException rather than the 409 being tested.
        headers: <String, List<String>>{
          Headers.contentTypeHeader: <String>[Headers.jsonContentType],
          'Retry-After': <String>['2'],
        },
      ),
      data: <String, dynamic>{'items': <dynamic>[]},
    );

    await expectLater(
      api.post('/pos/checkout', data: <String, dynamic>{'items': <dynamic>[]}),
      throwsA(
        isA<RequestInProgressException>().having(
          (RequestInProgressException e) => e.retryAfter,
          'retryAfter',
          const Duration(seconds: 2),
        ),
      ),
    );
  });

  test('429 surfaces how long to wait', () async {
    adapter.onPost(
      '/forgot-password',
      (server) => server.reply(
        429,
        <String, dynamic>{'message': 'Too Many Attempts.'},
        headers: <String, List<String>>{
          Headers.contentTypeHeader: <String>[Headers.jsonContentType],
          'Retry-After': <String>['60'],
        },
      ),
      data: <String, dynamic>{'email': 'a@b.test'},
    );

    await expectLater(
      api.post('/forgot-password', data: <String, dynamic>{'email': 'a@b.test'}),
      throwsA(
        isA<RateLimitException>().having(
          (RateLimitException e) => e.retryAfter,
          'retryAfter',
          const Duration(seconds: 60),
        ),
      ),
    );
  });

  test('5xx is a ServerException, not a network one', () async {
    adapter.onGet(
      '/dashboard/kpis',
      (server) => server.reply(500, <String, dynamic>{}),
    );

    await expectLater(
      api.get('/dashboard/kpis'),
      throwsA(
        isA<ServerException>()
            .having((ServerException e) => e.statusCode, 'status', 500),
      ),
    );
  });

  test('a connection failure is a NetworkException', () async {
    adapter.onGet(
      '/menu',
      (server) => server.throws(
        0,
        DioException.connectionError(
          requestOptions: RequestOptions(path: '/menu'),
          reason: 'no route to host',
        ),
      ),
    );

    await expectLater(api.get('/menu'), throwsA(isA<NetworkException>()));
  });
}
