<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesman_id' => $this->salesman_id,
            'salesman' => $this->whenLoaded('salesman', fn () => $this->salesman?->name),
            'name' => $this->name,
            'day_of_week' => $this->day_of_week,
            'area' => $this->area,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'stops_count' => $this->whenCounted('stops'),
            'stops' => $this->whenLoaded('stops', fn () => $this->stops->map(fn ($stop) => [
                'id' => $stop->id,
                'customer_id' => $stop->customer_id,
                'customer' => $stop->relationLoaded('customer') ? $stop->customer?->name : null,
                'sequence' => $stop->sequence,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
