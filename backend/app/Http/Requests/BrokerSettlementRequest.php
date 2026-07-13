<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrokerSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'bank', 'upi', 'adjustment'])],
            'settlement_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
