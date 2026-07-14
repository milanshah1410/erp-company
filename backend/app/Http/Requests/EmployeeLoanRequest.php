<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $loan = $this->route('employeeLoan');
        $tenantId = app(TenantContext::class)->id();

        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            // Optional: auto-generated when omitted.
            'loan_number' => [
                'nullable', 'string', 'max:50',
                Rule::unique('employee_loans', 'loan_number')->where('tenant_id', $tenantId)->ignore($loan?->id),
            ],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'interest_rate' => ['sometimes', 'numeric', 'min:0'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:600'],
            'disbursed_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
