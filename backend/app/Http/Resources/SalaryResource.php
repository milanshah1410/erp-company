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
            'salary_structure_id' => $this->salary_structure_id,
            'month' => $this->month?->format('Y-m-d'),

            // Earnings
            'basic' => (float) $this->basic,
            'hra' => (float) $this->hra,
            'conveyance' => (float) $this->conveyance,
            'medical' => (float) $this->medical,
            'special_allowance' => (float) $this->special_allowance,
            'other_earnings' => (float) $this->other_earnings,
            'overtime_amount' => (float) $this->overtime_amount,
            'bonus_amount' => (float) $this->bonus_amount,
            'allowances' => (float) $this->allowances,
            'gross' => (float) $this->gross,

            // Deductions
            'pf_employee' => (float) $this->pf_employee,
            'pf_employer' => (float) $this->pf_employer,
            'esi_employee' => (float) $this->esi_employee,
            'esi_employer' => (float) $this->esi_employer,
            'professional_tax' => (float) $this->professional_tax,
            'tds' => (float) $this->tds,
            'advance_deduction' => (float) $this->advance_deduction,
            'loan_deduction' => (float) $this->loan_deduction,
            'other_deductions' => (float) $this->other_deductions,
            'deductions' => (float) $this->deductions,

            'net_salary' => (float) $this->net_salary,

            // Attendance basis
            'working_days' => (float) $this->working_days,
            'present_days' => (float) $this->present_days,
            'paid_days' => (float) $this->paid_days,
            'lop_days' => (float) $this->lop_days,

            'auto_generated' => (bool) $this->auto_generated,
            'status' => $this->status,
            'paid_on' => $this->paid_on?->format('Y-m-d'),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
