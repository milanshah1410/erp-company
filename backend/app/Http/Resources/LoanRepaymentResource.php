<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanRepaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_loan_id' => $this->employee_loan_id,
            'installment_no' => $this->installment_no,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'paid_on' => $this->paid_on?->format('Y-m-d'),
            'salary_id' => $this->salary_id,
        ];
    }
}
