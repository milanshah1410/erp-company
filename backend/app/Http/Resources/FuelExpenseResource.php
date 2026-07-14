<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SerializesWorkflowFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FuelExpenseResource extends JsonResource
{
    use SerializesWorkflowFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_number' => $this->expense_number,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle?->label()),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'fuel_date' => $this->fuel_date?->toDateString(),
            'fuel_type' => $this->fuel_type,
            'litres' => $this->litres,
            'rate_per_litre' => $this->rate_per_litre,
            'amount' => $this->amount,
            'odometer' => $this->odometer,
            'station' => $this->station,
            ...$this->workflowFields(),
        ];
    }
}
