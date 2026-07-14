<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim("{$this->employee->employee_code} — {$this->employee->first_name} {$this->employee->last_name}")),
            'loan_number' => $this->loan_number,
            'principal' => (float) $this->principal,
            'interest_rate' => (float) $this->interest_rate,
            'tenure_months' => (int) $this->tenure_months,
            'emi' => (float) $this->emi,
            'total_payable' => (float) $this->total_payable,
            'repaid' => (float) $this->repaid,
            'outstanding' => $this->outstanding(),
            'disbursed_on' => $this->disbursed_on?->format('Y-m-d'),
            'reason' => $this->reason,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'repayments' => LoanRepaymentResource::collection($this->whenLoaded('repayments')),
            'created_at' => $this->created_at,
        ];
    }
}
