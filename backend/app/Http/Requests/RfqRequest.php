<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_indent_id' => ['nullable', 'exists:purchase_indents,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'rfq_date' => ['required', 'date'],
            'response_deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['exists:suppliers,id'],
        ];
    }
}
