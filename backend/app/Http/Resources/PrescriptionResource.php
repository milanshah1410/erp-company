<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prescription_number' => $this->prescription_number,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ] : null),

            'patient_name' => $this->patient_name,
            'patient_age' => $this->patient_age,
            'patient_gender' => $this->patient_gender,
            'patient_phone' => $this->patient_phone,

            'doctor_name' => $this->doctor_name,
            'doctor_registration_no' => $this->doctor_registration_no,
            'hospital' => $this->hospital,

            'prescribed_on' => $this->prescribed_on?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            // A slip with no validity date is single-use; with one, it repeats
            // until that date. The counter needs to know which it is holding.
            'is_repeat' => $this->valid_until !== null,
            'is_dispensable' => $this->resource->isDispensable(),
            'blocked_reason' => $this->resource->blockedReason(),

            'notes' => $this->notes,
            'attachment_path' => $this->attachment_path,
            'dispenses_count' => $this->dispenses_count ?? null,
            'dispenses' => $this->whenLoaded('dispenses', fn () => $this->dispenses->map(fn ($d) => [
                'id' => $d->id,
                'product_name' => $d->product_name,
                'drug_schedule' => $d->drug_schedule,
                'quantity' => (float) $d->quantity,
                'dispensed_on' => $d->dispensed_on?->toDateString(),
                'invoice' => $d->relationLoaded('invoice') ? $d->invoice?->invoice_number : null,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
