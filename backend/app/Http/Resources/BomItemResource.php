<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ] : null),
            'variant_id' => $this->variant_id,
            'quantity' => $this->quantity,
            'wastage_percent' => $this->wastage_percent,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'remarks' => $this->remarks,
        ];
    }
}
