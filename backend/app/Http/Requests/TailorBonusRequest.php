<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TailorBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'tailor_id' => ['required', 'integer', 'exists:tailors,id'],
            'basis' => ['required', 'in:pieces,efficiency,ontime,manual'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'pieces' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            // Optional: when omitted the service derives it from the basis.
            'amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:draft,approved,paid'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
