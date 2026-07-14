<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TailorAlterationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'tailor_work_id' => ['nullable', 'integer', 'exists:tailor_works,id'],
            'tailor_id' => ['nullable', 'integer', 'exists:tailors,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,in_progress,done'],
            'charge' => ['nullable', 'numeric', 'min:0'],
            'reported_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
