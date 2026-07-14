<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'opening_balance' => $this->opening_balance,
            'tailors_count' => $this->whenCounted('tailors'),
            'outstanding_balance' => $this->when($this->relationLoaded('tailors'), fn () => $this->outstandingBalance()),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
