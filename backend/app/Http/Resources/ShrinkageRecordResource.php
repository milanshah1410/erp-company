<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShrinkageRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'record_number' => $this->record_number,
            'fabric_roll_id' => $this->fabric_roll_id,
            'fabric_roll' => $this->whenLoaded('fabricRoll', fn () => $this->fabricRoll?->roll_number),
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product?->name),
            'process' => $this->process,
            'length_before' => $this->length_before,
            'length_after' => $this->length_after,
            'width_before' => $this->width_before,
            'width_after' => $this->width_after,
            'length_shrinkage_pct' => $this->length_shrinkage_pct,
            'width_shrinkage_pct' => $this->width_shrinkage_pct,
            'test_date' => $this->test_date?->toDateString(),
            'tested_by' => $this->tested_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
