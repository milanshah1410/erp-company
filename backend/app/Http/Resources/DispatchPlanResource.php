<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DispatchPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dispatch_number' => $this->dispatch_number,
            'dispatch_date' => $this->dispatch_date?->toDateString(),
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle?->label()),
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver?->name),
            'route_id' => $this->route_id,
            'route' => $this->whenLoaded('route', fn () => $this->route?->name),
            'status' => $this->status,
            'remarks' => $this->remarks,
            'dispatched_at' => $this->dispatched_at,
            'delivered_at' => $this->delivered_at,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : null),
            'delivered_count' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->where('delivery_status', 'delivered')->count(),
            ),
            'items' => DispatchItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
        ];
    }
}
