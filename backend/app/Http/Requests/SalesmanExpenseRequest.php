<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesmanExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'salesman_id' => ['required', 'integer', 'exists:salesmen,id'],
            'expense_date' => ['required', 'date'],
            'category' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
        ];
    }
}
