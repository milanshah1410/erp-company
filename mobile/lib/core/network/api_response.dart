import '../errors/api_exception.dart';

/// Decoders for the shapes this backend actually returns.
///
/// The original mobile brief (§29) assumed a `{success, message, data, errors}`
/// envelope. This API does not use one — it returns native Laravel shapes across
/// 198 controllers, and the Vue web client is built against them. Rather than
/// retro-fit an envelope onto a working system, the client adapts here, in one
/// file, and every repository reads through it.
///
/// The four shapes, and nothing else:
///
/// ```jsonc
/// { "data": [...], "links": {...}, "meta": { "current_page": 1, ... } } // paginated list
/// { "data": {...} }                                                     // single resource
/// { "token": "...", "user": {...} }                                     // bare payload (login)
/// { "message": "Product deleted." }                                     // action ack
/// ```
class ApiResponse {
  const ApiResponse._();

  /// Peels the `data` wrapper when an API Resource added one, and passes
  /// anything else through untouched.
  ///
  /// Both cases are real on the same API: `ProductResource` wraps, while
  /// `POST /login` returns `{token, user}` at the top level. A caller should not
  /// have to know which — and a caller that guesses wrong gets a null field
  /// rather than an error, which is the failure mode worth designing out.
  ///
  /// The `meta`/`links` check keeps a *paginated* body from being mistaken for a
  /// wrapped object, and the `Map` check keeps a resource whose payload happens
  /// to contain a legitimate `data` **list** (a collection endpoint) intact.
  static Map<String, dynamic> unwrap(dynamic body) {
    if (body is! Map<String, dynamic>) {
      throw UnexpectedException(
        'Expected a JSON object from the API but got ${body.runtimeType}.',
      );
    }

    final dynamic inner = body['data'];
    if (inner is Map<String, dynamic>) {
      return inner;
    }

    return body;
  }

  /// A single resource, mapped.
  static T single<T>(dynamic body, T Function(Map<String, dynamic>) parse) =>
      parse(unwrap(body));

  /// An unpaginated list — `{"data": [...]}`, which several lookup endpoints
  /// return (units, categories, warehouses: sets small enough to send whole).
  static List<T> list<T>(dynamic body, T Function(Map<String, dynamic>) parse) {
    final dynamic rows = (body is Map<String, dynamic>) ? body['data'] : body;

    if (rows is! List) {
      throw UnexpectedException(
        'Expected a JSON list from the API but got ${rows.runtimeType}.',
      );
    }

    return rows
        .whereType<Map<String, dynamic>>()
        .map(parse)
        .toList(growable: false);
  }

  /// The `message` from an action acknowledgement, for a snackbar.
  static String message(dynamic body, {String fallback = 'Done.'}) {
    if (body is Map<String, dynamic> && body['message'] is String) {
      return body['message'] as String;
    }
    return fallback;
  }
}

/// One page of a server-paginated list.
///
/// §17 is explicit that the app must never pull a whole dataset into memory, so
/// this type carries only what a list screen needs to decide whether to ask for
/// more — never the accumulated result, which belongs to the provider holding
/// the screen's state.
class Paginated<T> {
  const Paginated({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  /// Laravel's `meta` block, with defaults that degrade safely: an endpoint that
  /// returns a bare list (no `meta`) reads as a single complete page rather than
  /// throwing, and `hasMore` is then correctly false.
  factory Paginated.fromJson(
    dynamic body,
    T Function(Map<String, dynamic>) parse,
  ) {
    if (body is! Map<String, dynamic>) {
      throw UnexpectedException(
        'Expected a paginated JSON object but got ${body.runtimeType}.',
      );
    }

    final dynamic rows = body['data'];
    if (rows is! List) {
      throw const UnexpectedException(
        'Paginated response is missing its "data" array.',
      );
    }

    final Map<String, dynamic> meta =
        (body['meta'] as Map<String, dynamic>?) ?? const <String, dynamic>{};

    final List<T> items = rows
        .whereType<Map<String, dynamic>>()
        .map(parse)
        .toList(growable: false);

    return Paginated<T>(
      items: items,
      currentPage: _int(meta['current_page'], 1),
      lastPage: _int(meta['last_page'], 1),
      perPage: _int(meta['per_page'], items.length),
      total: _int(meta['total'], items.length),
    );
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;

  bool get hasMore => currentPage < lastPage;
  int get nextPage => currentPage + 1;
  bool get isEmpty => items.isEmpty;

  /// Laravel returns these as ints, but a JSON number that has been through a
  /// cast or a string column comes back as a String often enough to be worth
  /// tolerating rather than crashing a list screen over.
  static int _int(dynamic value, int fallback) => switch (value) {
        final int v => v,
        final num v => v.toInt(),
        final String v => int.tryParse(v) ?? fallback,
        _ => fallback,
      };

  Paginated<T> merge(Paginated<T> next) => Paginated<T>(
        items: <T>[...items, ...next.items],
        currentPage: next.currentPage,
        lastPage: next.lastPage,
        perPage: next.perPage,
        total: next.total,
      );
}
