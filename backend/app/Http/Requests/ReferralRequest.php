<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'referrer_customer_id' => ['required', 'integer', 'exists:customers,id'],
            'referred_name' => ['required', 'string', 'max:255'],
            'referred_phone' => ['nullable', 'string', 'max:255'],
            'reward_points' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
