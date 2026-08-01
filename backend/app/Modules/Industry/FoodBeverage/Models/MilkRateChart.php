<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The chart on the collection-centre wall.
 *
 * Milk is not bought by the litre — it is bought by the fat in the litre, and at
 * an organised dairy by fat and SNF together. Three bases are supported because
 * all three are in daily use:
 *
 *  - 'flat'    — a village point with no tester. One rate, every can.
 *  - 'fat'     — most private dairies. rate = fat% × rate-per-fat-point.
 *  - 'fat_snf' — where the plant pays on both legs.
 *
 * The rate this returns is per litre. `adjustment_per_litre` rides on top of it,
 * which is how a seasonal incentive or a transport deduction is applied without
 * rewriting the chart the farmers can see.
 */
class MilkRateChart extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'name', 'milk_type', 'basis', 'flat_rate', 'rate_per_fat_point', 'rate_per_snf_point',
        'min_fat', 'max_fat', 'adjustment_per_litre', 'effective_from', 'is_active', 'created_by',
    ];

    protected $casts = [
        'flat_rate' => 'decimal:4',
        'rate_per_fat_point' => 'decimal:4',
        'rate_per_snf_point' => 'decimal:4',
        'min_fat' => 'decimal:2',
        'max_fat' => 'decimal:2',
        'adjustment_per_litre' => 'decimal:4',
        'effective_from' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * The chart in force for a milk type on a date — latest effective one wins.
     *
     * Falls back to a chart of type 'mixed' before giving up, so a dairy that runs
     * one chart for everything does not have to enter it three times.
     */
    public static function inForce(string $milkType, ?string $onDate = null): ?self
    {
        $date = $onDate ?: now()->toDateString();

        return static::query()
            ->where('is_active', true)
            ->whereIn('milk_type', array_unique([$milkType, 'mixed']))
            ->whereDate('effective_from', '<=', $date)
            // A chart for the exact milk type beats the catch-all 'mixed' one,
            // and among equals the most recently effective wins.
            ->orderByRaw('CASE WHEN milk_type = ? THEN 0 ELSE 1 END', [$milkType])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Cans this chart has priced — what makes it undeletable. */
    public function collections(): HasMany
    {
        return $this->hasMany(MilkCollection::class, 'milk_rate_chart_id');
    }

    /**
     * Rate per litre for a given quality reading.
     *
     * Never returns a negative rate: a deduction big enough to invert the price
     * would otherwise turn a farmer's delivery into a debt, which is not a thing
     * a collection centre can hand someone a slip for.
     */
    public function rateFor(float $fatPercent, float $snfPercent = 0): float
    {
        $rate = match ($this->basis) {
            'flat' => (float) $this->flat_rate,
            'fat' => $fatPercent * (float) $this->rate_per_fat_point,
            'fat_snf' => ($fatPercent * (float) $this->rate_per_fat_point)
                + ($snfPercent * (float) $this->rate_per_snf_point),
            default => 0.0,
        };

        return round(max(0, $rate + (float) $this->adjustment_per_litre), 4);
    }

    /**
     * Is this reading inside the acceptance band?
     *
     * Reported, not enforced. Rejecting a farmer's can is a person's decision
     * taken at the counter, and software that refuses the entry just means the
     * milk is taken and never recorded.
     */
    public function acceptsFat(float $fatPercent): bool
    {
        return $fatPercent >= (float) $this->min_fat && $fatPercent <= (float) $this->max_fat;
    }
}
