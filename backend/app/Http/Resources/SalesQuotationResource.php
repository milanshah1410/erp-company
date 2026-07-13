<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesQuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_number' => $this->quote_number,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'status' => $this->status,
            'quote_date' => $this->quote_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'notes' => $this->notes,
            'sales_order_id' => $this->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->id,
                'so_number' => $this->salesOrder->so_number,
            ] : null),
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
                'unit_price' => $i->unit_price,
                'tax_percent' => $i->tax_percent,
                'tax_amount' => $i->tax_amount,
                'line_total' => $i->line_total,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
