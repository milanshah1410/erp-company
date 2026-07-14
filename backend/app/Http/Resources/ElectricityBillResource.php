<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SerializesWorkflowFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ElectricityBillResource extends JsonResource
{
    use SerializesWorkflowFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bill_number' => $this->bill_number,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'meter_number' => $this->meter_number,
            'provider' => $this->provider,
            'connection_name' => $this->connection_name,
            'bill_date' => $this->bill_date?->toDateString(),
            'billing_period_start' => $this->billing_period_start?->toDateString(),
            'billing_period_end' => $this->billing_period_end?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'previous_reading' => $this->previous_reading,
            'current_reading' => $this->current_reading,
            'units_consumed' => $this->units_consumed,
            'amount' => $this->amount,
            ...$this->workflowFields(),
        ];
    }
}
