<?php

declare(strict_types=1);

namespace App\Modules\Industry\Sports\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset on one contract.
 *
 * There is no quantity here and there never will be. The entire value of a hire
 * register is that four bikes are four identified bikes: "qty 4" cannot tell you
 * which two came back on Sunday, which one came back scratched, or which one is
 * still missing on Tuesday — and those are the only three things anyone asks.
 *
 * The rate, the deposit and the late-fee percentage are snapshots taken when the
 * line is written. The rate card behind them is a living thing that moves with
 * the season, and a contract that repriced itself because somebody edited the
 * fleet on Monday would be unbillable and undefendable.
 */
class RentalItem extends Model
{
    use BelongsToTenant;

    protected $table = 'sports_rental_items';

    protected $fillable = [
        'rental_id', 'asset_id', 'rate', 'deposit', 'late_fee_percent',
        'returned_at', 'condition_out', 'condition_in',
        'hire_units', 'hire_charge', 'late_units', 'late_charge',
        'damage_charge', 'damage_note',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'deposit' => 'decimal:2',
        'late_fee_percent' => 'integer',
        'returned_at' => 'datetime',
        'hire_units' => 'decimal:2',
        'hire_charge' => 'decimal:2',
        'late_units' => 'decimal:2',
        'late_charge' => 'decimal:2',
        'damage_charge' => 'decimal:2',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'condition_out' => 'good',
        'late_fee_percent' => 100,
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(RentalAsset::class, 'asset_id');
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }

    /** The rate charged for time past the promise — the ordinary one, uplifted. */
    public function lateRate(): float
    {
        return round((float) $this->rate * ((int) $this->late_fee_percent) / 100, 2);
    }

    /** Everything this line contributes to the bill. */
    public function chargeTotal(): float
    {
        return round(
            (float) $this->hire_charge + (float) $this->late_charge + (float) $this->damage_charge,
            2,
        );
    }

    /**
     * Did it come back worse than it went out?
     *
     * Ordered scale, so "fair out, worn in" is deterioration and "worn out, fair
     * in" is somebody having serviced it in between — which happens, and must not
     * read as damage.
     */
    public function deteriorated(): bool
    {
        $scale = ['new' => 0, 'good' => 1, 'fair' => 2, 'worn' => 3, 'damaged' => 4, 'lost' => 5];

        if ($this->condition_in === null) {
            return false;
        }

        return ($scale[$this->condition_in] ?? 0) > ($scale[$this->condition_out] ?? 0);
    }
}
