<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variantId = $this->route('variant')?->id;

        return [
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($variantId)],
            'barcode' => ['nullable', 'string', 'max:255'],
            'rfid_tag' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'rfid_tag')->ignore($variantId)],
            'name' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
