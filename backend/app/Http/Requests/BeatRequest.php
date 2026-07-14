<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'salesman_id' => ['required', 'integer', 'exists:salesmen,id'],
            'name' => ['required', 'string', 'max:255'],
            'day_of_week' => ['nullable', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'area' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],

            // Optional route stops managed inline with the beat.
            'customers' => ['nullable', 'array'],
            'customers.*.customer_id' => ['required_with:customers', 'integer', 'exists:customers,id'],
            'customers.*.sequence' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
