<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'rfid_tag' => $this->rfid_tag,
            'name' => $this->name,
            'attributes' => $this->getAttribute('attributes'),
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
