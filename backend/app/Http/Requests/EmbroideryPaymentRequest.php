<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmbroideryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'embroidery_vendor_id' => ['required', 'integer', 'exists:embroidery_vendors,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'bank', 'upi', 'adjustment'])],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
