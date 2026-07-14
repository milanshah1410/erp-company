<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesmanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $salesmanId = $this->route('salesman')?->id;

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('salesmen', 'code')->ignore($salesmanId)],
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'territory' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'default_incentive_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
