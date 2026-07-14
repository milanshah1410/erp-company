<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bom_number' => $this->bom_number,
            'name' => $this->name,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'variant_id' => $this->variant_id,
            'output_quantity' => $this->output_quantity,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'material_cost' => $this->material_cost,
            'items_count' => $this->whenCounted('items'),
            'notes' => $this->notes,
            'items' => BomItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
        ];
    }
}
