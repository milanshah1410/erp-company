<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesmanCollectionRequest extends FormRequest
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
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['nullable', 'in:cash,cheque,upi,bank'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'collection_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
