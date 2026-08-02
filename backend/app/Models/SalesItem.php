<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'sales_order_id', 'product_id', 'variant_id',
        'quantity', 'reserved_quantity', 'backorder_quantity',
        'unit_price', 'discount_type', 'discount_value', 'discount_amount',
        'tax_percent', 'tax_amount', 'line_total',
        'serials', 'batch_allocations',
        /**
         * Jewellery: the build-up the ornament was billed at, snapshotted so
         * tomorrow's rate card cannot restate an invoice the customer already
         * has. Written by JewelleryPricingService::snapshotFor(); null on every
         * line that is not an ornament, which is most lines in most shops.
         */
        'metal_rate', 'net_weight', 'metal_value', 'making_charge',
        'wastage_value', 'stone_value', 'hallmark_charge', 'stone_details',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'backorder_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'serials' => 'array',
        'batch_allocations' => 'array',
        'metal_rate' => 'decimal:2',
        'net_weight' => 'decimal:3',
        'metal_value' => 'decimal:2',
        'making_charge' => 'decimal:2',
        'wastage_value' => 'decimal:2',
        'stone_value' => 'decimal:2',
        'hallmark_charge' => 'decimal:2',
        'stone_details' => 'array',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
