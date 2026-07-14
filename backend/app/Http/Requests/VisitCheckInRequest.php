<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VisitCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'outcome' => ['nullable', 'string', 'max:255'],
            'sales_order_id' => ['nullable', 'integer', 'exists:sales_orders,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
