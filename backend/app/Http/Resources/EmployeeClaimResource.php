<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SerializesWorkflowFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeClaimResource extends JsonResource
{
    use SerializesWorkflowFields;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_number' => $this->claim_number,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->fullName()),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'claim_date' => $this->claim_date?->toDateString(),
            'title' => $this->title,
            'total_amount' => $this->total_amount,
            'items' => EmployeeClaimItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            ...$this->workflowFields(),
        ];
    }
}
