<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductStoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'stone_type' => ['required', Rule::in([
                'diamond', 'ruby', 'emerald', 'sapphire', 'pearl', 'cz', 'semi_precious', 'other',
            ])],
            'shape' => ['nullable', 'string', 'max:40'],
            'pieces' => ['required', 'integer', 'min:1', 'max:9999'],
            /**
             * `min:0` and not `gt:0`: a rough count of tiny chips is sometimes
             * entered by the piece with no weight taken, and refusing that would
             * push the shop back to describing them in the notes field.
             */
            'carat_weight' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'cut_grade' => ['nullable', 'string', 'max:30'],
            'colour_grade' => ['nullable', 'string', 'max:30'],
            'clarity_grade' => ['nullable', 'string', 'max:30'],
            'rate_type' => ['required', Rule::in(['per_carat', 'per_piece', 'flat'])],
            'rate' => ['nullable', 'numeric', 'min:0'],
            /**
             * Omitted means "take the arithmetic"; sent means the shop is
             * overruling it for a parcel bought as a job lot.
             * {@see App\Http\Controllers\Api\ProductStoneController::resolved()}
             */
            'value' => ['nullable', 'numeric', 'min:0'],
            'certificate_number' => ['nullable', 'string', 'max:60'],
            'certifying_lab' => ['nullable', 'string', 'max:40'],
            'certificate_date' => ['nullable', 'date'],
            'setting_type' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
