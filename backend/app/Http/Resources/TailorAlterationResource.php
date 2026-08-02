<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TailorAlterationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alteration_number' => $this->alteration_number,
            'tailor_work_id' => $this->tailor_work_id,
            'work_number' => $this->whenLoaded('work', fn () => $this->work?->work_number),
            'tailor_id' => $this->tailor_id,
            'tailor_name' => $this->whenLoaded('tailor', fn () => $this->tailor?->name),
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            // The retail half: whose garment, on which bill, in which size.
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
            'variant_id' => $this->variant_id,
            'variant_name' => $this->whenLoaded('variant', fn () => $this->variant?->name),
            'qty' => $this->qty,
            'reason' => $this->reason,
            'status' => $this->status,
            'charge' => $this->charge,
            'reported_date' => $this->reported_date?->toDateString(),
            'promised_date' => $this->promised_date?->toDateString(),
            // Computed, not stored — a job promised for yesterday is late this
            // morning without waiting for anything to run.
            'is_late' => $this->isLate(),
            'completed_date' => $this->completed_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
