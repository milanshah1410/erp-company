<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'country_id' => $this->country_id,
            'country' => $this->whenLoaded('country', fn () => $this->country?->name),
            'city' => $this->city,
            'state' => $this->state,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
