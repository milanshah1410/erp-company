<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SerializesWorkflowFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleExpenseResource extends JsonResource
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
            'expense_date' => $this->expense_date?->toDateString(),
            'expense_type' => $this->expense_type,
            'odometer' => $this->odometer,
            'amount' => $this->amount,
            'payee' => $this->payee,
            ...$this->workflowFields(),
        ];
    }
}
