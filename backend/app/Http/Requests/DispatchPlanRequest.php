<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DispatchPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'dispatch_date' => ['required', 'date'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'exists:drivers,id'],
            'route_id' => ['nullable', 'exists:delivery_routes,id'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.customer_id' => ['nullable', 'exists:customers,id'],
            'items.*.sales_order_id' => ['nullable', 'exists:sales_orders,id'],
            'items.*.delivery_address' => ['nullable', 'string'],
            'items.*.packages' => ['nullable', 'integer', 'min:1'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.cod_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
