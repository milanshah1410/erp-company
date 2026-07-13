<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable stock movement record. Append-only: no updated_at, never mutated.
 */
class StockLedger extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'warehouse_id', 'product_id', 'variant_id', 'type',
        'quantity_in', 'quantity_out', 'balance', 'unit_cost',
        'reference_type', 'reference_id', 'reference_number', 'notes', 'user_id',
    ];

    protected $casts = [
        'quantity_in' => 'decimal:3',
        'quantity_out' => 'decimal:3',
        'balance' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
