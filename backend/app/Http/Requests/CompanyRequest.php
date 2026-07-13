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

    /**
     * Uppercase the tax identifiers so format validation is case-insensitive
     * for the client but the stored value stays canonical.
     */
    protected function prepareForValidation(): void
    {
        foreach (['gstin', 'pan'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        $companyId = $this->route('company')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('companies', 'code')->ignore($companyId)],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
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

    public function messages(): array
    {
        return [
            'gstin.regex' => 'The GSTIN format is invalid. Expected 15 characters, e.g. 27ABCDE1234F1Z5.',
            'pan.regex' => 'The PAN format is invalid. Expected 10 characters, e.g. ABCDE1234F.',
        ];
    }
}
