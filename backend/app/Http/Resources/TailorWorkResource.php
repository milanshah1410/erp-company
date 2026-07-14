<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TailorWorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_number' => $this->work_number,
            'tailor_id' => $this->tailor_id,
            'tailor_name' => $this->whenLoaded('tailor', fn () => $this->tailor?->name),
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'product_variant_id' => $this->product_variant_id,
            'rate_card_id' => $this->rate_card_id,
            'issued_qty' => $this->issued_qty,
            'completed_qty' => $this->completed_qty,
            'rejected_qty' => $this->rejected_qty,
            'scrapped_qty' => $this->scrapped_qty,
            'remaining_qty' => $this->remainingQty(),
            'rate' => $this->rate,
            'amount' => round((float) $this->rate * (float) $this->issued_qty, 2),
            'earned' => round((float) $this->rate * (float) $this->completed_qty, 2),
            'status' => $this->status,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
