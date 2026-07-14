<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrokerCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $scope = $this->input('scope');

        return [
            'scope' => ['required', Rule::in(['product', 'customer'])],
            'product_id' => [Rule::requiredIf($scope === 'product'), 'nullable', 'exists:products,id'],
            'customer_id' => [Rule::requiredIf($scope === 'customer'), 'nullable', 'exists:customers,id'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        // Clear the irrelevant target so a product rate never carries a
        // customer_id and vice-versa.
        if (($data['scope'] ?? null) === 'product') {
            $data['customer_id'] = null;
        } elseif (($data['scope'] ?? null) === 'customer') {
            $data['product_id'] = null;
        }

        return $data;
    }
}
