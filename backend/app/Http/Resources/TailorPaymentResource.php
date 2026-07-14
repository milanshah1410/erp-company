<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TailorPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tailor_id' => $this->tailor_id,
            'tailor_name' => $this->whenLoaded('tailor', fn () => $this->tailor?->name),
            'payment_number' => $this->payment_number,
            'amount' => $this->amount,
            'mode' => $this->mode,
            'payment_date' => $this->payment_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
