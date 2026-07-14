<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesmanVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'salesman_id' => ['required', 'integer', 'exists:salesmen,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'beat_id' => ['nullable', 'integer', 'exists:beats,id'],
            'visit_date' => ['required', 'date'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:planned,visited,skipped,cancelled'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
