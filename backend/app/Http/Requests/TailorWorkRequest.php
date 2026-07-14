<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TailorWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'tailor_id' => ['required', 'integer', 'exists:tailors,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'issued_qty' => ['required', 'numeric', 'gt:0'],
            // Optional per-piece rate override; when omitted the controller
            // snapshots the product's rate-card effective rate.
            'rate' => ['nullable', 'numeric', 'min:0'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
