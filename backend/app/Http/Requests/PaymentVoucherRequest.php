<?php

namespace App\Http\Requests;

use App\Models\PaymentVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['inbound', 'outbound'])],

            // Party is optional (party-less movements such as standalone expenses).
            'party_type' => ['nullable', Rule::in(array_keys(PaymentVoucher::PARTY_TYPES))],
            'party_id' => ['nullable', 'integer', 'required_with:party_type'],

            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(PaymentVoucher::MODES)],

            'reference_no' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'cheque_no' => ['nullable', 'string', 'max:255', 'required_if:mode,cheque'],
            'cheque_date' => ['nullable', 'date', 'required_if:mode,cheque'],
            'cheque_status' => ['nullable', Rule::in(['pending', 'cleared', 'bounced'])],

            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            // Optional up-front allocation across open documents. Omit to book an advance.
            'allocations' => ['nullable', 'array'],
            'allocations.*.allocatable_type' => ['required_with:allocations', Rule::in(array_keys(PaymentVoucher::ALLOCATABLE_TYPES))],
            'allocations.*.allocatable_id' => ['required_with:allocations', 'integer'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ];
    }
}
