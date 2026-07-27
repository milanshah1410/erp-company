<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $supplierId = $this->route('supplier')?->id;

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'code')->ignore($supplierId)],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_active' => ['boolean'],
            // Import & Export Logistics — overseas supplier attributes.
            'is_overseas' => ['boolean'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'port_id' => ['nullable', 'exists:ports,id'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'bank_details' => ['nullable', 'string', 'max:255'],
        ];
    }
}
