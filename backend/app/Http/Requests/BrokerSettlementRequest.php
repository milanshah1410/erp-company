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
            // Omit `amount` entirely to settle the broker's full outstanding
            // balance (see BrokerSettlementController::store).
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'bank', 'upi', 'adjustment'])],
            'tds_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settlement_date' => ['nullable', 'date'],
            'period_month' => ['nullable', 'date_format:Y-m'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
