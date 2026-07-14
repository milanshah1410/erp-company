<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $driverId = $this->route('driver')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('drivers', 'code')->where('tenant_id', $tenantId)->ignore($driverId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'license_no' => ['nullable', 'string', 'max:60'],
            'license_type' => ['nullable', 'string', 'max:60'],
            'license_expiry' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'joining_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
