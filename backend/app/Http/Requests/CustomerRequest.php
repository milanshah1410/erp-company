<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('customers', 'code')->ignore($customerId)],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'type' => ['required', Rule::in(['retail', 'wholesale', 'distributor'])],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_active' => ['boolean'],
        ];
    }
}
