<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ElectricityBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'meter_number' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', 'string', 'max:150'],
            'connection_name' => ['nullable', 'string', 'max:150'],
            'bill_date' => ['required', 'date'],
            'billing_period_start' => ['nullable', 'date'],
            'billing_period_end' => ['nullable', 'date', 'after_or_equal:billing_period_start'],
            'due_date' => ['nullable', 'date'],
            'previous_reading' => ['nullable', 'numeric', 'min:0'],
            'current_reading' => ['nullable', 'numeric', 'min:0'],
            'units_consumed' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'upi', 'card', 'cheque', 'other'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
