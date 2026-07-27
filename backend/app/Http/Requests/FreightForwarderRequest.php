<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FreightForwarderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $forwarderId = $this->route('freightForwarder')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('freight_forwarders', 'code')->where('tenant_id', $tenantId)->ignore($forwarderId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'pan_number' => ['nullable', 'string', 'max:20'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
