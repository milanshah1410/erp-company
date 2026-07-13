<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branch = $this->route('branch');
        $companyId = $this->input('company_id', $branch?->company_id);

        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('branches', 'code')->where('company_id', $companyId)->ignore($branch?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            // "sometimes" so an omitted field falls back to the column's DB default instead of being written as null/false.
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
