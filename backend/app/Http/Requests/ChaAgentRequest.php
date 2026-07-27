<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChaAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $agentId = $this->route('chaAgent')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('cha_agents', 'code')->where('tenant_id', $tenantId)->ignore($agentId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'license_no' => ['nullable', 'string', 'max:60'],
            'license_expiry' => ['nullable', 'date'],
            'port_id' => ['nullable', 'exists:ports,id'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
