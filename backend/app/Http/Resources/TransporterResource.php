<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransporterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'gst_number' => $this->gst_number,
            'pan_number' => $this->pan_number,
            'transport_mode' => $this->transport_mode,
            'vehicle_types' => $this->vehicle_types,
            'address' => $this->address,
            'city' => $this->city,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
