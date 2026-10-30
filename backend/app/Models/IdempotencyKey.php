<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * One client-named write attempt (mobile app §18).
 *
 * No LogsAuditActivity: this is plumbing under a write that is itself audited.
 * Logging the receipt as well as the invoice would double every entry.
 */
class IdempotencyKey extends Model
{
    use BelongsToTenant;

    /** How long a key stays replayable. Comfortably longer than any retry loop. */
    public const TTL_HOURS = 24;

    protected $fillable = [
        'user_id', 'key', 'request_hash', 'method', 'path',
        'status', 'response_status', 'response_body', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Fingerprint of what was sent, so a key reused for a different request is
     * caught rather than answered from the first request's stored reply.
     *
     * The path is taken from the request rather than the route pattern: two
     * different invoices are different paths, and collapsing them to
     * `invoices/{invoice}` would make a reused key look legitimate.
     *
     * JSON bodies hash raw. Multipart hashes the *parsed* inputs instead, with
     * the uploaded files left out: the raw body there is file bytes, and hashing
     * it would mean holding an upload in memory a second time to learn nothing —
     * a receipt photo re-picked from the gallery is the same expense claim.
     *
     * Keys are sorted before encoding so that a retry which happens to serialise
     * its fields in a different order still reads as the same request.
     */
    public static function fingerprint(Request $request): string
    {
        $body = $request->isJson()
            ? (string) $request->getContent()
            : (string) json_encode(self::sortDeep($request->except(array_keys($request->allFiles()))));

        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            $body,
        ]));
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private static function sortDeep(array $input): array
    {
        ksort($input);

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::sortDeep($value);
            }
        }

        return $input;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /** Rows past their window; what `idempotency:prune` deletes. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
