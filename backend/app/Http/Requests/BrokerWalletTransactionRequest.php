<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrokerWalletTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            // Only manual movements are allowed here; commission/reversal/
            // settlement entries are posted by the service, not the API.
            'type' => ['required', Rule::in(['topup', 'advance', 'adjustment'])],
            'direction' => ['required_if:type,adjustment', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
