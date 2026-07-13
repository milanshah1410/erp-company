<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GstRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company->name),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'gstin' => $this->gstin,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'state_code' => $this->state_code,
            'state' => $this->state,
            'registration_type' => $this->registration_type,
            'address' => $this->address,
            'effective_from' => $this->effective_from?->toDateString(),
            'is_primary' => (bool) $this->is_primary,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
