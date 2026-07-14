<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'job_type' => ['nullable', 'string', 'max:150'],
            'job_work_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.finished_product_id' => ['nullable', 'exists:products,id'],
            'items.*.finished_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.issued_qty' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
