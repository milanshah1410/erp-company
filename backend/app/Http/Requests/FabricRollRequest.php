<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FabricRollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'shade_no' => ['nullable', 'string', 'max:100'],
            'dye_lot' => ['nullable', 'string', 'max:100'],
            'gsm' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'width_unit' => ['nullable', 'in:inch,cm'],
            'length_unit' => ['nullable', 'in:meter,yard'],
            'initial_length' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'received_date' => ['nullable', 'date'],
            'post_to_stock' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
