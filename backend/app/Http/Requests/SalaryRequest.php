<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize the salary period to the first day of its month before validation. */
    protected function prepareForValidation(): void
    {
        if ($this->filled('month')) {
            try {
                $this->merge(['month' => Carbon::parse($this->input('month'))->startOfMonth()->format('Y-m-d')]);
            } catch (\Throwable) {
                // leave as-is; the date rule will reject it.
            }
        }
    }

    public function rules(): array
    {
        $salary = $this->route('salary');
        $tenantId = app(TenantContext::class)->id();
        $employeeId = $this->input('employee_id', $salary?->employee_id);

        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'month' => [
                'required', 'date',
                // One salary record per employee per month.
                Rule::unique('salaries', 'month')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->ignore($salary?->id),
            ],
            'basic' => ['sometimes', 'numeric', 'min:0'],
            'allowances' => ['sometimes', 'numeric', 'min:0'],
            'deductions' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'paid'])],
            'paid_on' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
