<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'registration_no' => $this->registration_no,
            'name' => $this->name,
            'type' => $this->type,
            'make' => $this->make,
            'model' => $this->model,
            'fuel_type' => $this->fuel_type,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
