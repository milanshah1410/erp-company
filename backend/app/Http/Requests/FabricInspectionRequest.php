<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FabricInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fabric_roll_id' => ['required', 'exists:fabric_rolls,id'],
            'inspected_length' => ['required', 'numeric', 'min:0.001'],
            'inspected_width' => ['nullable', 'numeric', 'min:0'],
            'result' => ['nullable', 'in:pending,pass,reject'],
            'inspector' => ['nullable', 'string', 'max:150'],
            'inspection_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'defects' => ['nullable', 'array'],
            'defects.*.defect_type' => ['required_with:defects', 'string', 'max:150'],
            'defects.*.severity' => ['nullable', 'in:minor,major,critical'],
            'defects.*.size_range' => ['nullable', 'string', 'max:100'],
            'defects.*.points' => ['required_with:defects', 'numeric', 'min:0', 'max:4'],
            'defects.*.count' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
