<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ColourPrintingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'printer_vendor_id' => ['required', 'integer', 'exists:suppliers,id'],
            'print_type' => ['required', Rule::in(['screen', 'digital', 'sublimation', 'dtf', 'vinyl', 'block', 'offset'])],
            'screen_count' => ['nullable', 'integer', 'min:0'],
            'colour_count' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
