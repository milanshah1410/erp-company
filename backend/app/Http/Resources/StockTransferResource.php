<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'from_warehouse_id' => $this->from_warehouse_id,
            'from_warehouse' => $this->whenLoaded('fromWarehouse', fn () => [
                'id' => $this->fromWarehouse->id,
                'name' => $this->fromWarehouse->name,
            ]),
            'to_warehouse_id' => $this->to_warehouse_id,
            'to_warehouse' => $this->whenLoaded('toWarehouse', fn () => [
                'id' => $this->toWarehouse->id,
                'name' => $this->toWarehouse->name,
            ]),
            'status' => $this->status,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),
            'notes' => $this->notes,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->relationLoaded('product') && $i->product ? [
                    'id' => $i->product->id,
                    'sku' => $i->product->sku,
                    'name' => $i->product->name,
                ] : null,
                'variant_id' => $i->variant_id,
                'quantity' => $i->quantity,
                'unit_cost' => $i->unit_cost,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
