<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeBonusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim("{$this->employee->employee_code} — {$this->employee->first_name} {$this->employee->last_name}")),
            'month' => $this->month?->format('Y-m-d'),
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'salary_id' => $this->salary_id,
            'created_at' => $this->created_at,
        ];
    }
}
