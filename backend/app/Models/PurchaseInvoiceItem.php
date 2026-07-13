<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'purchase_invoice_id', 'purchase_item_id', 'product_id', 'variant_id',
        'quantity', 'unit_cost', 'tax_percent', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Variance of this invoice line against its PO line: quantity vs the
     * PO's cumulative GRN-received quantity, and unit cost vs the PO price.
     */
    public function variance(): array
    {
        $poItem = $this->purchaseItem;
        $tolerance = (float) Setting::get('purchase_match_tolerance_percent', '0');

        $receivedQuantity = (float) ($poItem->received_quantity ?? 0);
        $poUnitCost = (float) ($poItem->unit_cost ?? 0);

        $quantityVariance = round((float) $this->quantity - $receivedQuantity, 3);
        $priceVariance = round((float) $this->unit_cost - $poUnitCost, 4);
        $pricePct = $poUnitCost > 0 ? abs($priceVariance) / $poUnitCost * 100 : 0;

        $withinTolerance = abs($quantityVariance) <= 0.001 && $pricePct <= $tolerance;

        return [
            'received_quantity' => $receivedQuantity,
            'po_unit_cost' => $poUnitCost,
            'quantity_variance' => $quantityVariance,
            'price_variance' => $priceVariance,
            'within_tolerance' => $withinTolerance,
        ];
    }
}
