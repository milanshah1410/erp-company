<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One session's output from one animal or one flock.
 *
 * This is the row that makes a farm legible to an ERP. A farm's stock is not
 * bought, it is *made*: until this posts, the fifty litres in the can do not
 * exist anywhere in the system, and the sack of feed that produced them has not
 * left the store. Both directions are on this row on purpose — recording yield
 * in one place and feed in another is exactly how a farm ends up knowing its
 * production and not its cost.
 */
class FarmProductionLog extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'log_date', 'session', 'animal_id', 'flock_id',
        'product_id', 'warehouse_id', 'quantity', 'unit_cost',
        'fat_percent', 'snf_percent',
        'feed_product_id', 'feed_quantity',
        'mortality_count', 'culled_count',
        'posted_at', 'remarks', 'created_by',
    ];

    protected $casts = [
        'log_date' => 'date',
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'fat_percent' => 'decimal:2',
        'snf_percent' => 'decimal:2',
        'feed_quantity' => 'decimal:3',
        'mortality_count' => 'integer',
        'culled_count' => 'integer',
        'posted_at' => 'datetime',
    ];

    public function animal(): BelongsTo
    {
        return $this->belongsTo(LivestockAnimal::class, 'animal_id');
    }

    public function flock(): BelongsTo
    {
        return $this->belongsTo(LivestockFlock::class, 'flock_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function feedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'feed_product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Have this row's stock movements and flock counters been applied? */
    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    /** What produced it, for a list column: the tag, or the batch code. */
    public function sourceLabel(): ?string
    {
        return $this->animal?->tag_number ?? $this->flock?->batch_code;
    }

    /**
     * Kilogram-fat, the unit a dairy actually pays on. Null when fat was not
     * recorded — a missing test is not a zero-fat delivery.
     */
    public function kgFat(): ?float
    {
        return $this->fat_percent === null
            ? null
            : round((float) $this->quantity * (float) $this->fat_percent / 100, 3);
    }
}
