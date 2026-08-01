<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was actually left at one door on one shift.
 *
 * Zero quantity is meaningful and is not the same as no row: zero says the boy
 * went and the family declined, no row says nobody has told us yet. The month is
 * billed from these rows, so the distinction is money.
 *
 * `invoice_id` is what makes the monthly run idempotent — a second run picks up
 * only the rows still unbilled, so re-running after a crash cannot invoice a
 * household twice.
 */
class MilkSubscriptionDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'milk_subscription_id', 'delivery_date', 'shift', 'quantity', 'rate', 'amount',
        'status', 'invoice_id', 'remarks', 'recorded_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MilkSubscription::class, 'milk_subscription_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Rows a billing run should pick up: unbilled, and something was actually
     * left at the door.
     *
     * Skips are excluded here rather than filtered by the caller so no billing
     * path can accidentally charge for a day the household was away — the one
     * mistake that loses a milk customer for good.
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereNull('invoice_id')
            ->where('status', '!=', 'skipped')
            ->where('quantity', '>', 0);
    }

    /** Price the row from its own rate — never the subscription's current one. */
    public function recompute(): self
    {
        $this->amount = round((float) $this->quantity * (float) $this->rate, 2);

        return $this;
    }
}
