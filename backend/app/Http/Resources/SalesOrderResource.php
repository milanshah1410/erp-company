<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'so_number' => $this->so_number,
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
            'channel' => $this->channel,
            'order_date' => $this->order_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'item_discount_total' => $this->item_discount_total,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'tcs_section' => $this->tcs_section,
            'tcs_rate' => (float) $this->tcs_rate,
            'tcs_amount' => (float) $this->tcs_amount,
            'round_off' => $this->round_off,
            'total' => $this->total,
            'notes' => $this->notes,
            'fulfillment_stage' => $this->fulfillment_stage,
            'picked_at' => $this->picked_at,
            'packed_at' => $this->packed_at,
            'shipped_at' => $this->shipped_at,
            'delivered_at' => $this->delivered_at,
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
                'reserved_quantity' => $i->reserved_quantity,
                'backorder_quantity' => $i->backorder_quantity,
                'unit_price' => $i->unit_price,
                'discount_type' => $i->discount_type,
                'discount_value' => $i->discount_value,
                'discount_amount' => $i->discount_amount,
                'tax_percent' => $i->tax_percent,
                'tax_amount' => $i->tax_amount,
                'line_total' => $i->line_total,
                /**
                 * Jewellery: the build-up this ornament was billed at, as it
                 * stood on the day. Columns rather than a relation, and null on
                 * every line that is not an ornament — so a shop that sells no
                 * metal carries eight nulls it never reads, and one that does
                 * can reprint the split without re-deriving it from a rate card
                 * that has since moved.
                 */
                'metal_rate' => $i->metal_rate,
                'net_weight' => $i->net_weight,
                'metal_value' => $i->metal_value,
                'making_charge' => $i->making_charge,
                'wastage_value' => $i->wastage_value,
                'stone_value' => $i->stone_value,
                'hallmark_charge' => $i->hallmark_charge,
                'stone_details' => $i->stone_details,
            ])),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'total' => $this->invoice->total,
                'paid_amount' => $this->invoice->paid_amount,
                'status' => $this->invoice->status,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
