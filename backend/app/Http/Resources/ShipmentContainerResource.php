<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentContainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->whenLoaded('shipment', fn () => $this->shipment?->shipment_number),
            'direction' => $this->whenLoaded('shipment', fn () => $this->shipment?->direction),
            'shipment_status' => $this->whenLoaded('shipment', fn () => $this->shipment?->status),
            'eta' => $this->whenLoaded('shipment', fn () => $this->shipment?->eta?->toDateString()),
            'container_number' => $this->container_number,
            'container_type' => $this->container_type,
            'seal_number' => $this->seal_number,
            'status' => $this->status,
            'current_location' => $this->current_location,
            'status_updated_at' => $this->status_updated_at,
            'packages' => $this->packages,
            'gross_weight_kg' => $this->gross_weight_kg,
            'net_weight_kg' => $this->net_weight_kg,
            'cbm' => $this->cbm,
            'free_days' => $this->free_days,
            'detention_free_until' => $this->detention_free_until?->toDateString(),
            'detention_days' => $this->detentionDays(),
            'accruing_detention' => $this->isAccruingDetention(),
            'empty_returned_on' => $this->empty_returned_on?->toDateString(),
            'is_damaged' => $this->is_damaged,
            'damage_remarks' => $this->damage_remarks,
            'remarks' => $this->remarks,
        ];
    }
}
