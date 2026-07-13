<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrokerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $brokerId = $this->route('broker')?->id;

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('brokers', 'code')->ignore($brokerId)],
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_commission' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_active' => ['boolean'],
        ];
    }
}
