<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $tenantId = app(TenantContext::class)->id();

        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_code' => [
                'required', 'string', 'max:50',
                Rule::unique('employees', 'employee_code')->where('tenant_id', $tenantId)->ignore($employee?->id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'biometric_user_id' => ['nullable', 'string', 'max:50'],
            'designation' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['sometimes', Rule::in(['full-time', 'part-time', 'contract', 'intern'])],
            'date_of_joining' => ['nullable', 'date'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
