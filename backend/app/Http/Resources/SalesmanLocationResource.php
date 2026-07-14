<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesmanLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesman_id' => $this->salesman_id,
            'recorded_at' => $this->recorded_at,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy' => $this->accuracy,
            'speed' => $this->speed,
            'battery' => $this->battery,
            'source' => $this->source,
        ];
    }
}
