<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->route('company')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('companies', 'code')->ignore($companyId)],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            // "sometimes" so an omitted field falls back to the column's DB default instead of being written as null/false.
            'financial_year_start_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'currency_symbol' => ['sometimes', 'nullable', 'string', 'max:5'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
