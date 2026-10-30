<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a write endpoint safe to retry (mobile app §18 "Offline Sync").
 *
 * The mobile app queues transactions while offline and drains the queue when the
 * signal comes back. It cannot know whether a request that timed out was lost on
 * the way there or on the way back, so it must retry — and without this
 * middleware a retried `POST /api/pos/checkout` posts the bill a second time.
 *
 * Usage:  ->middleware('idempotent')
 *
 * The client sends `Idempotency-Key: <uuid>`, minted once per business intent
 * and re-sent unchanged on every retry of *that* intent. The first request to
 * claim the key does the work; later arrivals are answered from the stored
 * reply.
 *
 * **Fails open when the header is absent.** The Vue SPA does not send one and
 * must keep working exactly as it does today, so this is an opt-in guarantee a
 * client asks for rather than a new requirement imposed on every caller. That is
 * also why it is safe to attach to existing routes.
 *
 * Four outcomes, and the distinction between them is the whole point:
 *
 *  - **First arrival** → run it, store the reply, return it.
 *  - **Retry after completion** → replay the stored reply, `Idempotency-Replayed:
 *    true`, ledger untouched.
 *  - **Retry while the first is still running** → `409`. Serving a half-written
 *    response would be worse than asking the client to come back.
 *  - **Same key, different body** → `422`. That is a client bug (a key reused
 *    across two different bills), and answering it with the first bill's response
 *    would hand the till the wrong invoice number.
 *
 * A failed write leaves no key behind: the row is dropped on any non-2xx and on
 * any thrown exception, because nothing was committed and the client must be
 * free to genuinely retry. Only JSON replies are storable, which every endpoint
 * this is attached to returns.
 */
class EnsureIdempotency
{
    /** Below this a "key" is too guessable to be a key; above it, someone is confused. */
    private const MIN_LENGTH = 8;

    private const MAX_LENGTH = 128;

    /** Bodies larger than this are not worth carrying in a replay row. */
    private const MAX_BODY_BYTES = 262144;

    public function handle(Request $request, Closure $next): Response
    {
        // Reads are already repeatable.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));

        // No key asked for, no guarantee given — see the class docblock.
        if ($key === '') {
            return $next($request);
        }

        $length = strlen($key);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return response()->json([
                'message' => 'The Idempotency-Key header must be between '.self::MIN_LENGTH.' and '.self::MAX_LENGTH.' characters.',
                'error' => 'idempotency_key_invalid',
            ], 400);
        }

        $tenantId = app(TenantContext::class)->id();

        // This middleware only ever runs behind `resolve.tenant`, so this is a
        // guard against a future misconfiguration rather than a live branch:
        // without a tenant the unique index has nothing to scope to.
        if (! $tenantId) {
            return $next($request);
        }

        $fingerprint = IdempotencyKey::fingerprint($request);

        $record = $this->claim($request, $key, $fingerprint);

        // Somebody else holds the key: replay, refuse, or ask them to wait.
        if (! $record instanceof IdempotencyKey) {
            return $record;
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Nothing was committed — a validation failure, a business rule
            // refusal, a crash. Release the key so the client can retry for
            // real once it has fixed whatever was wrong.
            $record->delete();

            throw $e;
        }

        $this->remember($record, $response);

        return $response;
    }

    /**
     * Take the key, or explain why we cannot.
     *
     * The unique index does the arbitration: the insert that lands first wins,
     * and the duplicate-key error the loser gets is precisely the signal that
     * another request already owns this key.
     *
     * @return IdempotencyKey|Response The claimed row, or the response to send instead.
     */
    private function claim(Request $request, string $key, string $fingerprint): IdempotencyKey|Response
    {
        try {
            return IdempotencyKey::create([
                'user_id' => $request->user()?->id,
                'key' => $key,
                'request_hash' => $fingerprint,
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 255),
                'status' => 'in_progress',
                'expires_at' => now()->addHours(IdempotencyKey::TTL_HOURS),
            ]);
        } catch (QueryException $e) {
            $existing = IdempotencyKey::where('key', $key)->first();

            // The insert failed for some other reason entirely (a missing table,
            // a broken connection). Not ours to swallow.
            if (! $existing) {
                throw $e;
            }

            return $this->resolveExisting($existing, $fingerprint);
        }
    }

    /** What to do about a key somebody already holds. */
    private function resolveExisting(IdempotencyKey $existing, string $fingerprint): Response
    {
        if ($existing->request_hash !== $fingerprint) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used for a different request. Generate a new key for each transaction.',
                'error' => 'idempotency_key_reuse',
            ], 422);
        }

        if (! $existing->isCompleted()) {
            return response()->json([
                'message' => 'An identical request is still being processed. Retry in a moment.',
                'error' => 'idempotency_in_progress',
            ], 409)->header('Retry-After', '2');
        }

        return response()
            ->json(
                json_decode((string) $existing->response_body, true),
                $existing->response_status ?? 200,
            )
            ->header('Idempotency-Replayed', 'true');
    }

    /**
     * Store a successful reply so the next retry can be answered from it.
     *
     * Anything else releases the key. A 422 means the bill was rejected and the
     * corrected bill must be allowed through; a 500 means we do not know what
     * happened and pretending we do would be worse than letting the client ask
     * again.
     */
    private function remember(IdempotencyKey $record, Response $response): void
    {
        $status = $response->getStatusCode();
        $body = $response->getContent();

        $storable = $status >= 200
            && $status < 300
            && is_string($body)
            && strlen($body) <= self::MAX_BODY_BYTES
            && str_contains((string) $response->headers->get('Content-Type'), 'json');

        if (! $storable) {
            $record->delete();

            return;
        }

        $record->update([
            'status' => 'completed',
            'response_status' => $status,
            'response_body' => $body,
            'completed_at' => now(),
        ]);
    }
}
