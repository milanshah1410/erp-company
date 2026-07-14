<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FabricCuttingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cutting_number' => $this->cutting_number,
            'fabric_roll_id' => $this->fabric_roll_id,
            'fabric_roll' => $this->whenLoaded('fabricRoll', fn () => $this->fabricRoll ? [
                'id' => $this->fabricRoll->id,
                'roll_number' => $this->fabricRoll->roll_number,
                'length_unit' => $this->fabricRoll->length_unit,
                'product' => $this->fabricRoll->relationLoaded('product') && $this->fabricRoll->product
                    ? ['id' => $this->fabricRoll->product->id, 'name' => $this->fabricRoll->product->name]
                    : null,
            ] : null),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'cut_date' => $this->cut_date?->toDateString(),
            'length_used' => $this->length_used,
            'length_consumed' => $this->length_consumed,
            'cutting_loss' => $this->cutting_loss,
            'leftover_length' => $this->leftover_length,
            'loss_pct' => $this->loss_pct,
            'pieces_produced' => $this->pieces_produced,
            'leftover_roll_id' => $this->leftover_roll_id,
            'leftover_roll' => $this->whenLoaded('leftoverRoll', fn () => $this->leftoverRoll?->roll_number),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
