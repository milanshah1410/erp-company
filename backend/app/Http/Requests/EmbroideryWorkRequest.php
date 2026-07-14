<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmbroideryWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'embroidery_vendor_id' => ['required', 'integer', 'exists:embroidery_vendors,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'design_number' => ['required', 'string', 'max:255'],
            'stitch_count' => ['nullable', 'integer', 'min:0'],
            'dispatched_qty' => ['required', 'numeric', 'gt:0'],
            'cost' => ['required', 'numeric', 'min:0'],
            'dispatch_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
