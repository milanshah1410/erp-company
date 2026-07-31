<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            // Internal projects have no customer; that is also what makes them
            // impossible to bill by the hour.
            'customer_id' => ['nullable', 'exists:customers,id'],
            'manager_id' => ['nullable', 'exists:employees,id'],
            'status' => ['nullable', Rule::in(['planning', 'active', 'on_hold', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'billing_type' => ['nullable', Rule::in(['fixed', 'time_and_material', 'non_billable'])],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Billing by the hour needs someone to bill.
            if ($this->input('billing_type') === 'time_and_material' && ! $this->input('customer_id')) {
                $validator->errors()->add(
                    'customer_id',
                    'A time-and-material project needs a customer to invoice.',
                );
            }
        });
    }
}
