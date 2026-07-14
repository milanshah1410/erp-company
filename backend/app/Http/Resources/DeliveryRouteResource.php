<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'area' => $this->area,
            'start_point' => $this->start_point,
            'end_point' => $this->end_point,
            'distance_km' => $this->distance_km,
            'estimated_hours' => $this->estimated_hours,
            'default_charge' => $this->default_charge,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
