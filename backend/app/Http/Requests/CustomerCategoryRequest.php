<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $categoryId = $this->route('customer_category')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('customer_categories', 'name')->ignore($categoryId),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'default_price_level' => ['required', Rule::in(['mrp', 'wholesale', 'retail', 'discount'])],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
