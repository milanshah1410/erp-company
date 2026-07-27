<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->whenLoaded('shipment', fn () => $this->shipment?->shipment_number),
            'direction' => $this->whenLoaded('shipment', fn () => $this->shipment?->direction),
            // Set for per-container heads (detention / demurrage).
            'shipment_container_id' => $this->shipment_container_id,
            'container_number' => $this->whenLoaded('container', fn () => $this->container?->container_number),
            'expense_type' => $this->expense_type,
            'expense_label' => $this->label(),
            'expense_date' => $this->expense_date?->toDateString(),
            'vendor_type' => $this->vendor_type,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor_name,
            'invoice_number' => $this->invoice_number,
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'amount' => $this->amount,
            'base_amount' => $this->base_amount,
            'payment_status' => $this->payment_status,
            'paid_amount' => $this->paid_amount,
            'outstanding_amount' => $this->outstandingAmount(),
            'due_date' => $this->due_date?->toDateString(),
            'is_billable' => $this->is_billable,
            'remarks' => $this->remarks,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
        ];
    }
}
