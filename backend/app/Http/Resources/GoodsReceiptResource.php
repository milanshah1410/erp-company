<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grn_number' => $this->grn_number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
                'status' => $this->purchaseOrder->status,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'supplier_id' => $this->supplier_id,
            'received_date' => $this->received_date?->toDateString(),
            'notes' => $this->notes,
            'landed_cost' => $this->whenLoaded('landedCost', fn () => $this->landedCost ? [
                'freight_amount' => $this->landedCost->freight_amount,
                'duty_amount' => $this->landedCost->duty_amount,
                'other_amount' => $this->landedCost->other_amount,
                'allocation_method' => $this->landedCost->allocation_method,
                'total_amount' => $this->landedCost->total_amount,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'purchase_item_id' => $i->purchase_item_id,
                'product_id' => $i->product_id,
                'product' => $i->relationLoaded('product') && $i->product ? [
                    'id' => $i->product->id,
                    'sku' => $i->product->sku,
                    'name' => $i->product->name,
                ] : null,
                'quantity' => $i->quantity,
                'unit_cost' => $i->unit_cost,
                'base_unit_cost' => $i->base_unit_cost,
                'landed_cost_per_unit' => $i->landed_cost_per_unit,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
