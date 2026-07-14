<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'expense_date' => ['required', 'date'],
            'expense_type' => ['required', Rule::in(['service', 'repair', 'insurance', 'tax', 'permit', 'fitness', 'toll', 'parking', 'fine', 'accessory', 'other'])],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payee' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'upi', 'card', 'cheque', 'other'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
