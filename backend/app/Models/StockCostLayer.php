<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt lot for FIFO/LIFO costing. Created by inward movements,
 * drawn down (remaining_quantity) by outward movements.
 */
class StockCostLayer extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'warehouse_id', 'product_id', 'variant_id', 'stock_ledger_id',
        'unit_cost', 'quantity', 'remaining_quantity',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:4',
        'quantity' => 'decimal:3',
        'remaining_quantity' => 'decimal:3',
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

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class, 'stock_ledger_id');
    }
}
