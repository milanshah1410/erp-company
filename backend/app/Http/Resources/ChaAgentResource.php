<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChaAgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'license_no' => $this->license_no,
            'license_expiry' => $this->license_expiry?->toDateString(),
            'port_id' => $this->port_id,
            'port' => $this->whenLoaded('port', fn () => $this->port?->name),
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'gst_number' => $this->gst_number,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
