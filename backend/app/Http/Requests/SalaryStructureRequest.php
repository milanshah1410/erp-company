<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'effective_from' => ['required', 'date'],
            'basic' => ['required', 'numeric', 'min:0'],
            'hra' => ['sometimes', 'numeric', 'min:0'],
            'conveyance' => ['sometimes', 'numeric', 'min:0'],
            'medical' => ['sometimes', 'numeric', 'min:0'],
            'special_allowance' => ['sometimes', 'numeric', 'min:0'],
            'other_allowances' => ['sometimes', 'numeric', 'min:0'],
            'pf_applicable' => ['sometimes', 'boolean'],
            'esi_applicable' => ['sometimes', 'boolean'],
            'pt_applicable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
