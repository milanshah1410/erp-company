<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'charge_number' => $this->charge_number,
            'charge_date' => $this->charge_date?->toDateString(),
            'dispatch_plan_id' => $this->dispatch_plan_id,
            'dispatch_number' => $this->whenLoaded('dispatchPlan', fn () => $this->dispatchPlan?->dispatch_number),
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle?->label()),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'transporter_name' => $this->transporter_name,
            'lr_number' => $this->lr_number,
            'charge_type' => $this->charge_type,
            'payment_mode' => $this->payment_mode,
            'payer' => $this->payer,
            'amount' => $this->amount,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
