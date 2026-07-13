<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'pincode' => $this->pincode,
            'financial_year_start_month' => (int) $this->financial_year_start_month,
            'currency_code' => $this->currency_code,
            'currency_symbol' => $this->currency_symbol,
            'is_active' => (bool) $this->is_active,
            'branches_count' => $this->whenCounted('branches'),
            'created_at' => $this->created_at,
        ];
    }
}
