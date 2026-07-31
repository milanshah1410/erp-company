<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'device_code' => $this->whenLoaded('device', fn () => $this->device?->code),
            'device_type' => $this->device_type,
            'driver' => $this->driver,
            'event' => $this->event,
            'direction' => $this->direction,
            'status' => $this->status,
            'source' => $this->source,
            'message' => $this->message,
            'payload' => $this->payload,
            'duration_ms' => $this->duration_ms,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'occurred_at' => $this->occurred_at,
        ];
    }
}
