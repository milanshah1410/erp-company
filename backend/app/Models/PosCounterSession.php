<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shift at one till: opened with a float, traded against, counted and closed.
 *
 * The money columns are snapshots, not projections. `expected_cash` and
 * `variance` are written once at close and never recomputed, because the number
 * a cashier signed off on has to keep saying what it said — a Z report that
 * silently re-derives itself is a Z report that cannot be used to hold anybody
 * to anything. Everything before close is derived live (see PosCounterService::
 * expectedCash), which is what the mid-shift X report reads.
 */
class PosCounterSession extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'counter_id', 'status', 'opened_by', 'opened_at', 'opening_float',
        'closed_by', 'closed_at', 'declared_cash', 'expected_cash', 'variance',
        'tender_totals', 'notes', 'open_flag',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'declared_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'variance' => 'decimal:2',
        'tender_totals' => 'array',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'opening_float' => 0,
    ];

    /**
     * Keep the nullable mirror of `status` in step with `status`.
     *
     * `open_flag` exists only to carry the partial-unique index that stops a
     * counter having two open drawers (MySQL treats NULLs as distinct, so a
     * nullable column gives "at most one open row" from an ordinary unique
     * index). Deriving it here rather than at each call site means the invariant
     * cannot be lost by a caller who forgot — including a future one closing a
     * session from somewhere that does not exist yet.
     */
    protected static function booted(): void
    {
        static::saving(function (self $session): void {
            $session->open_flag = $session->status === 'open' ? 'open' : null;
        });
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(PosCounter::class, 'counter_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** The money that passed through this drawer, refunds included as negatives. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'pos_session_id');
    }

    /** The bills rung up on this shift. */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'pos_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
