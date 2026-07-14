<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FabricRollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'roll_number' => $this->roll_number,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ] : null),
            'variant_id' => $this->variant_id,
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
            ] : null),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ] : null),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'shade_no' => $this->shade_no,
            'dye_lot' => $this->dye_lot,
            'gsm' => $this->gsm,
            'width' => $this->width,
            'width_unit' => $this->width_unit,
            'length_unit' => $this->length_unit,
            'initial_length' => $this->initial_length,
            'current_length' => $this->current_length,
            'consumed_length' => $this->consumed_length,
            'unit_cost' => $this->unit_cost,
            'grade' => $this->grade,
            'status' => $this->status,
            'is_leftover' => $this->is_leftover,
            'parent_roll_id' => $this->parent_roll_id,
            'parent_roll' => $this->whenLoaded('parentRoll', fn () => $this->parentRoll?->roll_number),
            'posted_to_stock' => $this->posted_to_stock,
            'received_date' => $this->received_date?->toDateString(),
            'notes' => $this->notes,
            'inspections_count' => $this->when(isset($this->inspections_count), $this->inspections_count),
            'cuttings_count' => $this->when(isset($this->cuttings_count), $this->cuttings_count),
            'created_at' => $this->created_at,
        ];
    }
}
