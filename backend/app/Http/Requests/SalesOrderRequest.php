<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'broker_id' => ['nullable', 'exists:brokers,id'],
            // Multi-broker per invoice. When given, the first broker also
            // becomes the order's primary broker_id (for display/back-compat).
            'brokers' => ['nullable', 'array'],
            'brokers.*.broker_id' => ['required', 'exists:brokers,id'],
            'brokers.*.commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'brokers.*.fixed_commission' => ['nullable', 'numeric', 'min:0'],
            'brokers.*.share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'order_date' => ['required', 'date'],
            'tcs_section' => ['nullable', 'string', 'max:50'],
            'tcs_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'discount_type' => ['nullable', 'in:percent,flat'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'in:percent,flat'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
