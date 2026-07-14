<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $rateCardId = $this->route('rateCard')?->id;

        return [
            // One rate card per product (style). Uniqueness is per-tenant; the
            // tenant scope is applied on the model, so we scope the DB rule too.
            'product_id' => [
                'required', 'integer', 'exists:products,id',
                Rule::unique('rate_cards', 'product_id')
                    ->ignore($rateCardId)
                    ->where('tenant_id', app(\App\Support\TenantContext::class)->id()),
            ],
            'standard_rate' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
