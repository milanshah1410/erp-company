<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->whenLoaded('shipment', fn () => $this->shipment?->shipment_number),
            'event_type' => $this->event_type,
            'event_label' => $this->label(),
            'event_at' => $this->event_at,
            'location' => $this->location,
            'description' => $this->description,
            'source' => $this->source,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
