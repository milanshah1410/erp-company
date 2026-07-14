<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class EmployeeBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Pin the bonus to the first day of its payroll month. */
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
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'month' => ['required', 'date'],
            'type' => ['sometimes', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
