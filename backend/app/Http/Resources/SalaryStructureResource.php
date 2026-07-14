<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryStructureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim("{$this->employee->employee_code} — {$this->employee->first_name} {$this->employee->last_name}")),
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'basic' => (float) $this->basic,
            'hra' => (float) $this->hra,
            'conveyance' => (float) $this->conveyance,
            'medical' => (float) $this->medical,
            'special_allowance' => (float) $this->special_allowance,
            'other_allowances' => (float) $this->other_allowances,
            'gross' => $this->grossEarnings(),
            'pf_applicable' => (bool) $this->pf_applicable,
            'esi_applicable' => (bool) $this->esi_applicable,
            'pt_applicable' => (bool) $this->pt_applicable,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
