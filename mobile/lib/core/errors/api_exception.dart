/// The failure taxonomy the whole app reasons about (§4).
///
/// Every network failure is converted into exactly one of these by
/// [ErrorInterceptor] before it leaves the network layer, so no repository,
/// provider or widget ever sees a raw `DioException` or an HTTP status code.
/// That is the point: a status code forces every caller to re-derive what it
/// means, and they will not all derive the same thing.
///
/// Sealed, so a `switch` over a failure is exhaustive and adding a new case
/// breaks compilation at the places that must handle it.
sealed class ApiException implements Exception {
  const ApiException(this.message);

  /// Safe to show a user as-is. Server-supplied where possible: the API already
  /// translates its messages into the caller's language via `X-Locale`, so
  /// passing them through is how the app speaks Gujarati without shipping a
  /// translation of every business rule.
  final String message;

  @override
  String toString() => '$runtimeType: $message';
}

/// No usable connection, or the request timed out (§4 "network timeout",
/// "offline").
///
/// The distinction from [ServerException] matters: this one is retryable and is
/// what feeds the offline queue in Phase 9. A server that answered with a 500
/// has *received* the request, and retrying it may double-post.
class NetworkException extends ApiException {
  const NetworkException(super.message, {this.isTimeout = false});

  final bool isTimeout;
}

/// 401 — the token is missing, expired or revoked.
///
/// Terminal for the session: the app clears the token and returns to sign-in.
/// There is no refresh to attempt, because Sanctum personal access tokens in
/// this backend do not expire and so have nothing to refresh (see
/// `docs/API_CONTRACT.md` §Auth). A 401 therefore means revoked, not stale.
class UnauthorizedException extends ApiException {
  const UnauthorizedException(super.message);
}

/// 403 — authenticated, but not allowed.
///
/// [code] carries the API's typed reason, which decides where the app goes next
/// rather than merely what it says:
///
/// * `tenant_suspended` → the suspended screen, **keeping the token** so the
///   user can still sign out or reach billing.
/// * `module_disabled` → the module is not on this workspace's plan.
/// * `menu_revoked`    → the operator withdrew this screen from this tenant.
/// * `tenant_missing` / `tenant_not_found` → the account is not attached to a
///   workspace; a support problem, not a user error.
class ForbiddenException extends ApiException {
  const ForbiddenException(super.message, {this.code});

  final String? code;

  bool get isTenantSuspended => code == 'tenant_suspended';
  bool get isModuleDisabled => code == 'module_disabled';
  bool get isMenuRevoked => code == 'menu_revoked';
}

/// 422 — the request was understood and refused, field by field.
///
/// Laravel's shape is `{message, errors: {field: [messages]}}`, and [errors]
/// preserves it so a form can mark the offending field rather than showing one
/// banner for a problem that belongs to a specific input.
class ValidationException extends ApiException {
  const ValidationException(super.message, this.errors);

  final Map<String, List<String>> errors;

  /// First message for a field, for `TextFormField.errorText`.
  String? forField(String field) {
    final List<String>? messages = errors[field];
    return (messages == null || messages.isEmpty) ? null : messages.first;
  }

  /// Every message, flattened — for a summary above a long form.
  List<String> get all =>
      errors.values.expand((List<String> messages) => messages).toList();
}

/// 429 — rate limited. [retryAfter] comes from the header when the server sends
/// one, so the UI can say how long rather than "try again later".
class RateLimitException extends ApiException {
  const RateLimitException(super.message, {this.retryAfter});

  final Duration? retryAfter;
}

/// 409 `idempotency_in_progress` — an identical request is still running.
///
/// Not an error the user should ever see: the sync queue waits and re-sends the
/// *same* key. Surfacing it as a failure would tempt a user into pressing the
/// button again, which is the exact thing idempotency exists to survive.
class RequestInProgressException extends ApiException {
  const RequestInProgressException(super.message, {this.retryAfter});

  final Duration? retryAfter;
}

/// 5xx, or a response the server could not produce. Received by the server, so
/// **not** blindly retryable for writes.
class ServerException extends ApiException {
  const ServerException(super.message, {this.statusCode});

  final int? statusCode;
}

/// Anything else — an unparseable body, an unexpected status, a bug here.
/// Deliberately last: if this is being thrown often, something above it is
/// missing a case.
class UnexpectedException extends ApiException {
  const UnexpectedException(super.message, {this.cause});

  final Object? cause;
}
