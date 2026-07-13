<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Batch extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'product_id', 'variant_id', 'warehouse_id',
        'batch_number', 'expiry_date', 'quantity', 'unit_cost',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Batches with on-hand quantity whose expiry falls within [$from, $to]. */
    public function scopeExpiringBetween(Builder $query, $from, $to): Builder
    {
        return $query->where('quantity', '>', 0)
            ->whereBetween('expiry_date', [$from, $to]);
    }

    /** Batches with on-hand quantity that have already expired. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString());
    }
}
