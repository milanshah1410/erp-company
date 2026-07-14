<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShrinkageRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fabric_roll_id' => ['nullable', 'exists:fabric_rolls,id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'process' => ['nullable', 'string', 'max:100'],
            'length_before' => ['required', 'numeric', 'min:0'],
            'length_after' => ['required', 'numeric', 'min:0'],
            'width_before' => ['nullable', 'numeric', 'min:0'],
            'width_after' => ['nullable', 'numeric', 'min:0'],
            'test_date' => ['required', 'date'],
            'tested_by' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
