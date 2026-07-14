<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('bankAccount')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('bank_accounts', 'name')->ignore($id)],
            'type' => ['required', Rule::in(['cash', 'bank'])],
            'is_petty_cash' => ['boolean'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_balance_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],
        ];
    }
}
