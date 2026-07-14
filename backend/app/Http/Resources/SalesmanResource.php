<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesmanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'user_id' => $this->user_id,
            'employee_id' => $this->employee_id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'territory' => $this->territory,
            'joining_date' => $this->joining_date?->toDateString(),
            'default_incentive_percent' => $this->default_incentive_percent,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'latest_location' => $this->whenLoaded('latestLocation', fn () => $this->latestLocation
                ? new SalesmanLocationResource($this->latestLocation)
                : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
