<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SerializesWorkflowFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FactoryMaintenanceResource extends JsonResource
{
    use SerializesWorkflowFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'maintenance_number' => $this->maintenance_number,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset?->name),
            'area' => $this->area,
            'maintenance_type' => $this->maintenance_type,
            'maintenance_date' => $this->maintenance_date?->toDateString(),
            'performed_by' => $this->performed_by,
            'downtime_hours' => $this->downtime_hours,
            'amount' => $this->amount,
            ...$this->workflowFields(),
        ];
    }
}
