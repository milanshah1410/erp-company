<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'title' => ['required', 'string', 'max:200'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // 0 = unlimited visits for the term.
            'visits_allowed' => ['nullable', 'integer', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'expired', 'cancelled'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
