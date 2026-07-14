<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PieceRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rate_card_id' => $this->rate_card_id,
            'product_name' => $this->whenLoaded('rateCard', fn () => $this->rateCard?->product?->name),
            'operation' => $this->operation,
            'rate' => $this->rate,
            'sequence' => $this->sequence,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
