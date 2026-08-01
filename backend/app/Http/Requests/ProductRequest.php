<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesCustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    use ValidatesCustomFields;

    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    protected function customFieldEntity(): string
    {
        return 'product';
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        // The tenant's own fields are appended, so a book store's ISBN is
        // validated exactly like `sku` without this file knowing it exists.
        return $this->customFieldRules() + [
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($productId)],
            'barcode' => ['nullable', 'string', 'max:255'],
            'rfid_tag' => ['nullable', 'string', 'max:255', Rule::unique('products', 'rfid_tag')->ignore($productId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'], // values for category attribute definitions
            'track_expiry' => ['boolean'],
            'track_batch' => ['boolean'],
            'track_serial' => ['boolean'],
            'has_variants' => ['boolean'],
            // Labour / visit charges: billable and taxable, but never stocked.
            'is_service' => ['boolean'],
            // Pharmacy: h/h1/x may not be sold without a prescription; h1/x
            // also land in the statutory register.
            'drug_schedule' => ['nullable', Rule::in(['none', 'g', 'h', 'h1', 'x'])],
            'composition' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            // Agriculture: what a farmer asks for. `agri_input_type` also decides
            // which licence the licence register expects to find behind this
            // item, so it is the one field here that is more than a description.
            'agri_input_type' => ['nullable', Rule::in(['seed', 'fertilizer', 'pesticide', 'feed', 'equipment', 'other'])],
            'crop' => ['nullable', 'string', 'max:100'],
            'variety' => ['nullable', 'string', 'max:100'],
            'technical_name' => ['nullable', 'string', 'max:255'],
            'nutrient_content' => ['nullable', 'string', 'max:100'],
            'germination_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
