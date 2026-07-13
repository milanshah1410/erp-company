<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $warehouseId = $this->route('warehouse')?->id;

        return [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $this->input('company_id')),
            ],
            'code' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'code')->ignore($warehouseId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['main', 'branch', 'transit', 'damaged'])],
            'contact_person' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ];
    }
}
