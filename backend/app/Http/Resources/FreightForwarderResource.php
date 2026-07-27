<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreightForwarderResource extends JsonResource
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
            'country_id' => $this->country_id,
            'country' => $this->whenLoaded('country', fn () => $this->country?->name),
            'address' => $this->address,
            'city' => $this->city,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
