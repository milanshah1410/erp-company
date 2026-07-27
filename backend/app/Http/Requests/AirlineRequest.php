<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AirlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $airlineId = $this->route('airline')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:10',
                Rule::unique('airlines', 'code')->where('tenant_id', $tenantId)->ignore($airlineId),
            ],
            'icao_code' => ['nullable', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:255'],
            'awb_prefix' => ['nullable', 'string', 'max:5'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => $this->filled('code') ? strtoupper((string) $this->input('code')) : null,
            'icao_code' => $this->filled('icao_code') ? strtoupper((string) $this->input('icao_code')) : null,
        ], fn ($v) => $v !== null));
    }
}
