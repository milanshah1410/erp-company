<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_integration_id' => $this->channel_integration_id,
            'integration' => $this->whenLoaded('integration', fn () => [
                'id' => $this->integration->id,
                'name' => $this->integration->name,
                'platform' => $this->integration->platform,
            ]),
            'external_order_id' => $this->external_order_id,
            'external_order_number' => $this->external_order_number,
            'order_status' => $this->order_status,
            'financial_status' => $this->financial_status,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'shipping_address' => $this->shipping_address,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'placed_at' => $this->placed_at,
            'items' => $this->items ?? [],
            'import_status' => $this->import_status,
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
            'import_error' => $this->import_error,
            'created_at' => $this->created_at,
        ];
    }
}
