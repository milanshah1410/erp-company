<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_code' => $this->asset_code,
            'name' => $this->name,
            'category' => $this->category,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'location' => $this->location,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_value' => $this->purchase_value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
