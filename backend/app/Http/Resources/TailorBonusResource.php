<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TailorBonusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bonus_number' => $this->bonus_number,
            'tailor_id' => $this->tailor_id,
            'tailor_name' => $this->whenLoaded('tailor', fn () => $this->tailor?->name),
            'basis' => $this->basis,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'pieces' => $this->pieces,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
