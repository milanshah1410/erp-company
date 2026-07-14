<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RfqQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'quotes' => ['required', 'array', 'min:1'],
            'quotes.*.rfq_item_id' => ['required', 'exists:rfq_items,id'],
            'quotes.*.unit_price' => ['required', 'numeric', 'min:0'],
            'quotes.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quotes.*.remarks' => ['nullable', 'string'],
        ];
    }
}
