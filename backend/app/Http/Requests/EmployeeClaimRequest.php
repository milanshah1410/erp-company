<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'claim_date' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'upi', 'card', 'cheque', 'other'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'items.*.expense_date' => ['nullable', 'date'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
