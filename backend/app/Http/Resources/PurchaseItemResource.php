<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
                'track_batch' => (bool) $this->product->track_batch,
                'track_expiry' => (bool) $this->product->track_expiry,
                'track_serial' => (bool) $this->product->track_serial,
            ]),
            'variant_id' => $this->variant_id,
            'quantity' => $this->quantity,
            'received_quantity' => $this->received_quantity,
            'outstanding_quantity' => $this->outstandingQuantity(),
            'unit_cost' => $this->unit_cost,
            'tax_percent' => $this->tax_percent,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
        ];
    }
}
