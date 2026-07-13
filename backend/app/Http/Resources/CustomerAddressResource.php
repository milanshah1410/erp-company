<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'type' => $this->type,
            'label' => $this->label,
            'is_default' => $this->is_default,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'pincode' => $this->pincode,
            'gstin' => $this->gstin,
            'formatted' => $this->formatted(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
