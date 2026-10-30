import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

/// The header the Laravel `EnsureIdempotency` middleware reads.
const String kIdempotencyKeyHeader = 'Idempotency-Key';

/// Set by the server on a replayed response, i.e. this exact intent had already
/// been carried out. Useful for telling a sync queue "already done" apart from
/// "just done" — neither is an error, but only one of them moved money.
const String kIdempotencyReplayedHeader = 'Idempotency-Replayed';

const Uuid _uuid = Uuid();

/// A key for one **business intent** — one bill, one payment, one adjustment.
///
/// Mint it when the user commits the action, not when the HTTP call is made, and
/// keep it for every retry of that action. That distinction is the entire
/// mechanism: a key minted per HTTP attempt makes every retry look like a fresh
/// bill, which is precisely the bug idempotency exists to prevent.
///
/// So a queued transaction stores its key alongside its payload and re-sends
/// both, even across an app restart.
String newIdempotencyKey() => _uuid.v4();

/// Request options carrying an idempotency key.
///
/// Use for every write that costs money or moves stock: POS checkout, returns,
/// exchanges, payments, stock adjustments and transfers, expenses. Cheap to add
/// and impossible to add retroactively once a duplicate invoice exists.
Options idempotent(String key, {Options? base}) {
  final Options options = base ?? Options();
  return options.copyWith(
    headers: <String, dynamic>{
      ...?options.headers,
      kIdempotencyKeyHeader: key,
    },
  );
}

/// Whether the server answered from its stored copy rather than doing the work
/// again.
bool wasReplayed(Response<dynamic> response) =>
    response.headers.value(kIdempotencyReplayedHeader) == 'true';
