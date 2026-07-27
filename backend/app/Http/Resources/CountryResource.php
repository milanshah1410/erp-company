<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'iso3' => $this->iso3,
            'name' => $this->name,
            'currency_code' => $this->currency_code,
            'dial_code' => $this->dial_code,
            'region' => $this->region,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
