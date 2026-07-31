<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_number' => $this->job_number,

            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'service_contract_id' => $this->service_contract_id,
            'contract' => $this->whenLoaded('contract', fn () => $this->contract ? [
                'id' => $this->contract->id,
                'contract_number' => $this->contract->contract_number,
                'title' => $this->contract->title,
                'end_date' => $this->contract->end_date?->toDateString(),
            ] : null),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'assigned_to' => $this->assigned_to,
            'technician' => $this->whenLoaded('technician', fn () => $this->technician ? [
                'id' => $this->technician->id,
                'employee_code' => $this->technician->employee_code,
                'name' => $this->technician->fullName(),
            ] : null),

            'status' => $this->status,
            'priority' => $this->priority,
            'item_name' => $this->item_name,
            'item_serial' => $this->item_serial,
            'complaint' => $this->complaint,
            'diagnosis' => $this->diagnosis,
            'resolution' => $this->resolution,
            'is_warranty' => (bool) $this->is_warranty,

            'received_at' => $this->received_at?->toIso8601String(),
            'promised_at' => $this->promised_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            // Anything promised for the past that is not finished yet — the one
            // derived flag the board actually sorts on.
            'is_overdue' => $this->promised_at
                && $this->promised_at->isPast()
                && ! in_array($this->status, ['completed', 'delivered', 'cancelled'], true),

            'labour_charge' => (float) $this->labour_charge,
            'labour_tax_percent' => (float) $this->labour_tax_percent,
            'parts_total' => (float) $this->parts_total,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'round_off' => (float) $this->round_off,
            'total' => (float) $this->total,
            'is_billable' => $this->resource->isBillable(),

            'sales_order_id' => $this->sales_order_id,
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'total' => (float) $this->invoice->total,
                'status' => $this->invoice->status,
            ] : null),

            'notes' => $this->notes,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->relationLoaded('product') && $i->product ? [
                    'id' => $i->product->id,
                    'sku' => $i->product->sku,
                    'name' => $i->product->name,
                    'is_service' => (bool) $i->product->is_service,
                ] : null,
                'variant_id' => $i->variant_id,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount_type' => $i->discount_type,
                'discount_value' => (float) $i->discount_value,
                'discount_amount' => (float) $i->discount_amount,
                'tax_percent' => (float) $i->tax_percent,
                'tax_amount' => (float) $i->tax_amount,
                'line_total' => (float) $i->line_total,
                'is_issued' => (bool) $i->is_issued,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
