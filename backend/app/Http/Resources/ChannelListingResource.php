<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_integration_id' => $this->channel_integration_id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'variant_id' => $this->variant_id,
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
            ] : null),
            'external_id' => $this->external_id,
            'external_sku' => $this->external_sku,
            'listing_title' => $this->listing_title,
            'price' => $this->price !== null ? (float) $this->price : null,
            'status' => $this->status,
            'last_synced_at' => $this->last_synced_at,
        ];
    }
}
