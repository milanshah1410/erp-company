<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim("{$this->employee->employee_code} — {$this->employee->first_name} {$this->employee->last_name}")),
            'month' => $this->month?->format('Y-m-d'),
            'basic' => (float) $this->basic,
            'allowances' => (float) $this->allowances,
            'deductions' => (float) $this->deductions,
            'net_salary' => (float) $this->net_salary,
            'status' => $this->status,
            'paid_on' => $this->paid_on?->format('Y-m-d'),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
