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
            'qty' => $this->qty,
            'reason' => $this->reason,
            'status' => $this->status,
            'charge' => $this->charge,
            'reported_date' => $this->reported_date?->toDateString(),
            'completed_date' => $this->completed_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
