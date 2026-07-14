<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'license_no' => $this->license_no,
            'license_type' => $this->license_type,
            'license_expiry' => $this->license_expiry?->toDateString(),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name ?? $this->employee?->first_name),
            'joining_date' => $this->joining_date?->toDateString(),
            'address' => $this->address,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
