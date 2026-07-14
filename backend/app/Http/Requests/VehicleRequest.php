<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $vehicleId = $this->route('vehicle')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'registration_no' => [
                'required', 'string', 'max:50',
                Rule::unique('vehicles', 'registration_no')->where('tenant_id', $tenantId)->ignore($vehicleId),
            ],
            'name' => ['nullable', 'string', 'max:150'],
            'type' => ['required', Rule::in(['car', 'bike', 'truck', 'tempo', 'van', 'other'])],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'fuel_type' => ['nullable', Rule::in(['petrol', 'diesel', 'cng', 'electric', 'other'])],
            'purchase_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ];
    }
}
