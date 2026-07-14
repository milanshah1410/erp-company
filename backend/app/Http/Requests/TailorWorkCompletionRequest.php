<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TailorWorkCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'qty' => ['required', 'numeric', 'gt:0'],
            'completed_at' => ['nullable', 'date'],
            'received_to_stock' => ['boolean'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id', 'required_if:received_to_stock,true'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
