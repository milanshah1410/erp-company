<?php

declare(strict_types=1);

namespace App\Modules\Industry\ComputerIt\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A software licence key: the half of a computer shop's sale that has no
 * physical stock behind it.
 *
 * A key is not inventory and modelling it as inventory goes wrong immediately. It
 * is a string that is worth money exactly once, that may cover one seat or two
 * hundred, that may be perpetual or stop working in March, and whose whole value
 * depends on nobody ever giving it to a second customer. Stock arithmetic
 * expresses none of that — which is why this is its own register with a
 * uniqueness constraint on the key rather than a product with a quantity.
 *
 * `licence_type` is the field the counter is actually asked about: an OEM key
 * dies with the machine it was activated on and may not be moved to the
 * customer's next laptop, a retail key may, a volume key covers many seats off
 * one string. Allocating without recording which kind it is turns "can you move
 * my Windows across?" into a guess.
 */
class LicenceKey extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'computer_it_licence_keys';

    /** Types that are tied to the first machine they are activated on. */
    public const MACHINE_LOCKED_TYPES = ['oem'];

    protected $fillable = [
        'product_id', 'software', 'version', 'publisher', 'licence_type', 'licence_key',
        'seats', 'seats_used', 'customer_id', 'asset_id', 'invoice_id', 'vendor',
        'valid_from', 'valid_to', 'status', 'allocated_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'seats' => 'integer',
        'seats_used' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'allocated_at' => 'datetime',
    ];

    /**
     * Mirrors of the column defaults. allocate() compares seats against
     * seats_used, and a null-vs-int comparison on a freshly created row would
     * make the seat limit pass by accident.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'licence_type' => 'retail',
        'seats' => 1,
        'seats_used' => 0,
        'status' => 'in_stock',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The machine it was activated on — the primary install for a volume key. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'asset_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "Windows 11 Pro (OEM)" for pickers and printed handovers. */
    public function label(): string
    {
        $name = trim($this->software.' '.(string) $this->version);

        return "{$name} (".strtoupper($this->licence_type).')';
    }

    /**
     * The key with everything but its last group hidden.
     *
     * Lists are read over a counter with a customer standing at it, and a key on
     * screen is a key given away — so the register shows enough to recognise a
     * row and no more. The full string is returned only by the single-record
     * endpoint, which sits behind its own permission.
     */
    public function masked(): string
    {
        $key = (string) $this->licence_key;
        $tail = substr($key, -5);

        return str_repeat('•', max(0, min(20, strlen($key) - 5))).$tail;
    }

    /** Seats still available on this key. */
    public function seatsRemaining(): int
    {
        return max(0, (int) $this->seats - (int) $this->seats_used);
    }

    /** Has the subscription date passed? Perpetual keys never expire. */
    public function isExpired(?Carbon $on = null): bool
    {
        return $this->valid_to !== null && $this->valid_to->endOfDay()->lt($on ?? now());
    }

    /**
     * Is this key usable right now?
     *
     * Three separate ways to be unusable, and the register should not conflate
     * them: revoked (the publisher pulled it), expired (the subscription ran
     * out), and full (every seat is spoken for). A key can be perfectly valid
     * and still have nothing left to give.
     */
    public function isAvailable(): bool
    {
        return $this->status !== 'revoked'
            && ! $this->isExpired()
            && $this->seatsRemaining() > 0;
    }

    /** May this key legally be moved to a different machine? */
    public function isTransferable(): bool
    {
        return ! in_array($this->licence_type, self::MACHINE_LOCKED_TYPES, true);
    }

    /**
     * Hand a seat to a customer, optionally naming the machine it went onto.
     *
     * Returns false rather than throwing when there is nothing left to give: the
     * caller is a counter endpoint that wants to say "this key is full" to the
     * person standing there, not to unwind a transaction.
     */
    public function allocate(?int $customerId, ?int $assetId = null, int $seats = 1): bool
    {
        if (! $this->isAvailable() || $seats > $this->seatsRemaining()) {
            return false;
        }

        $this->forceFill([
            'customer_id' => $customerId ?? $this->customer_id,
            // An OEM key belongs to the first machine it touches; a volume key's
            // asset column records the primary install and the seat count
            // carries the rest.
            'asset_id' => $assetId ?? $this->asset_id,
            'seats_used' => (int) $this->seats_used + $seats,
            'status' => 'allocated',
            'allocated_at' => $this->allocated_at ?? now(),
        ])->save();

        return true;
    }

    /**
     * Take a seat back — a machine returned, a mis-keyed allocation.
     *
     * The key goes back to `in_stock` only when the last seat comes back;
     * anything else stays `allocated` because it still is.
     */
    public function release(int $seats = 1): void
    {
        $used = max(0, (int) $this->seats_used - $seats);

        $this->forceFill([
            'seats_used' => $used,
            'status' => $used === 0 && $this->status === 'allocated' ? 'in_stock' : $this->status,
            'customer_id' => $used === 0 ? null : $this->customer_id,
            'asset_id' => $used === 0 ? null : $this->asset_id,
            'allocated_at' => $used === 0 ? null : $this->allocated_at,
        ])->save();
    }
}
