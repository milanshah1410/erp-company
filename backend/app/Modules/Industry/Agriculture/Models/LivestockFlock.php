<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A placement of birds, tracked as one batch.
 *
 * Nobody ear-tags a broiler. Five thousand day-old chicks arrive together, eat
 * the same feed, and are sold as one lot six weeks later — so the batch is the
 * unit, and every figure a poultry farmer is judged on is a ratio over it:
 * mortality against birds placed, feed against birds fed, eggs against birds
 * alive.
 *
 * The counters and roll-ups here are denormalised because those ratios are shown
 * on every screen; FarmProductionService is the only writer, and it moves them
 * inside the same guard that posts stock, so they cannot drift from the log.
 */
class LivestockFlock extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'batch_code', 'bird_type', 'breed', 'placed_on',
        'opening_birds', 'current_birds', 'mortality_birds', 'culled_birds', 'sold_birds',
        'chick_cost', 'supplier_id', 'warehouse_id',
        'feed_consumed_kg', 'eggs_collected',
        'status', 'closed_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'placed_on' => 'date',
        'closed_on' => 'date',
        'opening_birds' => 'integer',
        'current_birds' => 'integer',
        'mortality_birds' => 'integer',
        'culled_birds' => 'integer',
        'sold_birds' => 'integer',
        'chick_cost' => 'decimal:2',
        'feed_consumed_kg' => 'decimal:3',
        'eggs_collected' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productionLogs(): HasMany
    {
        return $this->hasMany(FarmProductionLog::class, 'flock_id');
    }

    /** Days since placement — the flock's age, which drives everything. */
    public function ageDays(): int
    {
        $end = $this->closed_on ?? now();

        return (int) $this->placed_on->startOfDay()->diffInDays($end, false);
    }

    /** Deaths as a share of birds placed. The first number anyone asks for. */
    public function mortalityPercent(): float
    {
        $opening = (int) $this->opening_birds;

        return $opening > 0 ? round($this->mortality_birds / $opening * 100, 2) : 0.0;
    }

    /** Feed drawn per bird placed, in kg. */
    public function feedPerBird(): float
    {
        $opening = (int) $this->opening_birds;

        return $opening > 0 ? round((float) $this->feed_consumed_kg / $opening, 3) : 0.0;
    }

    /**
     * Kilograms of feed behind a dozen eggs — the layer's real efficiency
     * number.
     *
     * Null for a flock that has laid nothing, and null for broilers: their FCR is
     * feed against *live weight gained*, and nothing here records weight at sale
     * yet. Reporting a broiler figure computed from eggs would be a lie dressed
     * as a metric, so the trade declares `weight_gain` as planned instead.
     */
    public function feedPerDozenEggs(): ?float
    {
        if ($this->bird_type === 'broiler' || (int) $this->eggs_collected <= 0) {
            return null;
        }

        return round((float) $this->feed_consumed_kg / ((int) $this->eggs_collected / 12), 3);
    }

    /**
     * Eggs per hundred birds alive, over a recent window — the lay percentage a
     * layer shed is run on. Takes the eggs and the days from the caller because
     * it is answered with one grouped query for a whole page of flocks, not one
     * query per row.
     */
    public function layPercent(int $eggs, int $days): ?float
    {
        $birds = (int) $this->current_birds;

        if ($this->bird_type === 'broiler' || $birds <= 0 || $days <= 0) {
            return null;
        }

        return round($eggs / $days / $birds * 100, 1);
    }
}
