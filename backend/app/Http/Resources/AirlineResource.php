<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AirlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'icao_code' => $this->icao_code,
            'name' => $this->name,
            'awb_prefix' => $this->awb_prefix,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
