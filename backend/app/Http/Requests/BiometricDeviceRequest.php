<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BiometricDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $device = $this->route('biometricDevice');
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('biometric_devices', 'code')->where('tenant_id', $tenantId)->ignore($device?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
