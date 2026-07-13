<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_item_id' => $this->purchase_item_id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'variant_id' => $this->variant_id,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'tax_percent' => $this->tax_percent,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'variance' => $this->relationLoaded('purchaseItem') ? $this->variance() : null,
        ];
    }
}
