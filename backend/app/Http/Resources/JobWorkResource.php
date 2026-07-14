<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobWorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_work_number' => $this->job_work_number,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'status' => $this->status,
            'job_type' => $this->job_type,
            'job_work_date' => $this->job_work_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'issued_date' => $this->issued_date?->toDateString(),
            'completed_date' => $this->completed_date?->toDateString(),
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
                'finished_product_id' => $i->finished_product_id,
                'finished_product' => $i->relationLoaded('finishedProduct') && $i->finishedProduct ? [
                    'id' => $i->finishedProduct->id,
                    'sku' => $i->finishedProduct->sku,
                    'name' => $i->finishedProduct->name,
                ] : null,
                'finished_variant_id' => $i->finished_variant_id,
                'issued_qty' => $i->issued_qty,
                'received_qty' => $i->received_qty,
                'damage_qty' => $i->damage_qty,
                'shortage_qty' => $i->shortage_qty,
                'pending_qty' => $i->pending_qty,
                'unit_cost' => $i->unit_cost,
                'finished_unit_cost' => $i->finished_unit_cost,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
