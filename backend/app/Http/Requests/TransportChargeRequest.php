<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransportChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'charge_date' => ['required', 'date'],
            'dispatch_plan_id' => ['nullable', 'exists:dispatch_plans,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'transporter_name' => ['nullable', 'string', 'max:255'],
            'lr_number' => ['nullable', 'string', 'max:100'],
            'charge_type' => ['required', Rule::in(['freight', 'loading', 'unloading', 'toll', 'detention', 'other'])],
            'payment_mode' => ['required', Rule::in(['paid', 'to_pay', 'to_be_billed'])],
            'payer' => ['required', Rule::in(['company', 'customer'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
