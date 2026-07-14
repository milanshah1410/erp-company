<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'rfid_tag' => $this->rfid_tag,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'brand_id' => $this->brand_id,
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'short_name' => $this->unit->short_name,
            ]),
            'hsn_code' => $this->hsn_code,
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'tax_percent' => $this->tax_percent,
            'min_stock_level' => $this->min_stock_level,
            'max_stock_level' => $this->max_stock_level,
            'reorder_level' => $this->reorder_level,
            'image' => $this->image,
            'image_url' => $this->image ? '/storage/'.ltrim($this->image, '/') : null,
            'attributes' => $this->getAttribute('attributes') ?? [],
            'track_expiry' => $this->track_expiry,
            'track_batch' => $this->track_batch,
            'track_serial' => $this->track_serial,
            'has_variants' => $this->has_variants,
            'is_active' => $this->is_active,
            'total_stock' => $this->when(
                $this->relationLoaded('stocks'),
                fn () => (float) $this->stocks->sum('quantity')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
