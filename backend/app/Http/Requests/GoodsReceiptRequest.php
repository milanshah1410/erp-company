<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'received_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'exists:purchase_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            // Put-away: target sub-location within the receiving warehouse.
            'items.*.location_id' => ['nullable', 'exists:warehouse_locations,id'],
            // Batch / expiry / serial capture for tracked products.
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.serials' => ['nullable', 'array'],
            'items.*.serials.*' => ['string', 'max:100'],
            // Landed cost: freight/duty/other charges apportioned across the receipt's lines.
            'landed_cost' => ['nullable', 'array'],
            'landed_cost.freight_amount' => ['nullable', 'numeric', 'min:0'],
            'landed_cost.duty_amount' => ['nullable', 'numeric', 'min:0'],
            'landed_cost.other_amount' => ['nullable', 'numeric', 'min:0'],
            'landed_cost.allocation_method' => ['nullable', Rule::in(['value', 'quantity'])],
        ];
    }
}
