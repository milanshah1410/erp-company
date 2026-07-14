<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SerializesWorkflowFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetMaintenanceResource extends JsonResource
{
    use SerializesWorkflowFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'maintenance_number' => $this->maintenance_number,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset?->name),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'maintenance_type' => $this->maintenance_type,
            'maintenance_date' => $this->maintenance_date?->toDateString(),
            'next_service_date' => $this->next_service_date?->toDateString(),
            'performed_by' => $this->performed_by,
            'amount' => $this->amount,
            ...$this->workflowFields(),
        ];
    }
}
