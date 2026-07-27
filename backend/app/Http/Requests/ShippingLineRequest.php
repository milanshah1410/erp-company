<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShippingLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $shippingLineId = $this->route('shippingLine')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('shipping_lines', 'code')->where('tenant_id', $tenantId)->ignore($shippingLineId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'scac_code' => ['nullable', 'string', 'max:10'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
