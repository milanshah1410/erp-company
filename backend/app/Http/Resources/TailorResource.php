<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TailorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contractor_id' => $this->contractor_id,
            'contractor_name' => $this->whenLoaded('contractor', fn () => $this->contractor?->name),
            'code' => $this->code,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'skill' => $this->skill,
            'opening_balance' => $this->opening_balance,
            'total_bonus' => $this->totalBonus(),
            'outstanding_balance' => $this->outstandingBalance(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
