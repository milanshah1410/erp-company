<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FuelExpenseRequest extends FormRequest
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
            'fuel_date' => ['required', 'date'],
            'fuel_type' => ['required', Rule::in(['petrol', 'diesel', 'cng', 'electric'])],
            'litres' => ['required', 'numeric', 'min:0.001'],
            'rate_per_litre' => ['required', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'station' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'upi', 'card', 'cheque', 'other'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
