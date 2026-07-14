<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmbroideryPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'embroidery_vendor_id' => $this->embroidery_vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'payment_number' => $this->payment_number,
            'amount' => $this->amount,
            'mode' => $this->mode,
            'payment_date' => $this->payment_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
