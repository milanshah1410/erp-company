<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'voucher_type' => ['required', Rule::in(['receipt', 'payment', 'contra', 'journal'])],
            'voucher_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['nullable', Rule::in(['cash', 'bank', 'upi', 'cheque', 'adjustment'])],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'counter_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id', 'different:bank_account_id'],
            'debit_ledger' => ['nullable', 'string', 'max:255'],
            'credit_ledger' => ['nullable', 'string', 'max:255'],
            'party_type' => ['nullable', 'string', Rule::in(['customer', 'supplier', 'broker', 'employee'])],
            'party_id' => ['nullable', 'integer'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }

    /**
     * Type-conditional requirements the flat rules can't express:
     *  - receipt/payment need a bank_account_id (the account affected)
     *  - contra needs both accounts (source + destination)
     *  - journal needs both ledger labels
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type = $this->input('voucher_type');

            if (in_array($type, ['receipt', 'payment'], true) && ! $this->filled('bank_account_id')) {
                $v->errors()->add('bank_account_id', 'A cash/bank account is required for receipt and payment vouchers.');
            }

            if ($type === 'contra') {
                if (! $this->filled('bank_account_id')) {
                    $v->errors()->add('bank_account_id', 'A destination account is required for a contra voucher.');
                }
                if (! $this->filled('counter_account_id')) {
                    $v->errors()->add('counter_account_id', 'A source account is required for a contra voucher.');
                }
            }

            if ($type === 'journal') {
                if (! $this->filled('debit_ledger')) {
                    $v->errors()->add('debit_ledger', 'A debit ledger label is required for a journal voucher.');
                }
                if (! $this->filled('credit_ledger')) {
                    $v->errors()->add('credit_ledger', 'A credit ledger label is required for a journal voucher.');
                }
            }
        });
    }
}
