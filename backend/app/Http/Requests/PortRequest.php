<?php

namespace App\Http\Requests;

use App\Models\Port;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $portId = $this->route('port')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('ports', 'code')->where('tenant_id', $tenantId)->ignore($portId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Port::TYPES)],
            'country_id' => ['nullable', 'exists:countries,id'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }
}
