<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'goods_receipt_id' => ['nullable', 'exists:goods_receipts,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'quality_score' => ['required', 'integer', 'min:1', 'max:5'],
            'delivery_score' => ['required', 'integer', 'min:1', 'max:5'],
            'pricing_score' => ['required', 'integer', 'min:1', 'max:5'],
            'service_score' => ['required', 'integer', 'min:1', 'max:5'],
            'rated_on' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
