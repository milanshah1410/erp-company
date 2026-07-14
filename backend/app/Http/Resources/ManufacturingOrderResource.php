<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManufacturingOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mo_number' => $this->mo_number,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'variant_id' => $this->variant_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'quantity_to_produce' => $this->quantity_to_produce,
            'quantity_produced' => $this->quantity_produced,
            'status' => $this->status,
            'order_date' => $this->order_date?->toDateString(),
            'expected_completion_date' => $this->expected_completion_date?->toDateString(),
            'material_cost' => $this->material_cost,
            'stage_cost' => $this->stage_cost,
            'total_cost' => $this->total_cost,
            'unit_production_cost' => $this->unitProductionCost(),
            'materials_consumed' => $this->materials_consumed,
            'finished_received' => $this->finished_received,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'notes' => $this->notes,
            'materials' => ManufacturingOrderMaterialResource::collection($this->whenLoaded('materials')),
            'stages' => ManufacturingOrderStageResource::collection($this->whenLoaded('stages')),
            'created_at' => $this->created_at,
        ];
    }
}
