<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $countryId = $this->route('country')?->id;
        $tenantId = app(TenantContext::class)->id();

        return [
            'code' => [
                'required', 'string', 'size:2',
                Rule::unique('countries', 'code')->where('tenant_id', $tenantId)->ignore($countryId),
            ],
            'iso3' => ['nullable', 'string', 'size:3'],
            'name' => ['required', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'region' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // ISO codes are conventionally upper-case; normalise so lookups and the
        // unique rule don't split on casing.
        $this->merge(array_filter([
            'code' => $this->filled('code') ? strtoupper((string) $this->input('code')) : null,
            'iso3' => $this->filled('iso3') ? strtoupper((string) $this->input('iso3')) : null,
            'currency_code' => $this->filled('currency_code') ? strtoupper((string) $this->input('currency_code')) : null,
        ], fn ($v) => $v !== null));
    }
}
