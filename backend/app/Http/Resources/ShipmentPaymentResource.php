<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->whenLoaded('shipment', fn () => $this->shipment?->shipment_number),
            'direction' => $this->direction,
            'payment_date' => $this->payment_date?->toDateString(),
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'amount' => $this->amount,
            'base_amount' => $this->base_amount,
            'mode' => $this->mode,
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount?->name),
            'reference' => $this->reference,
            'remarks' => $this->remarks,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
        ];
    }
}
