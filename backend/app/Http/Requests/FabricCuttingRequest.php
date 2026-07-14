<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FabricCuttingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fabric_roll_id' => ['required', 'exists:fabric_rolls,id'],
            'cut_date' => ['required', 'date'],
            'length_consumed' => ['required', 'numeric', 'min:0'],
            'cutting_loss' => ['nullable', 'numeric', 'min:0'],
            'leftover_length' => ['nullable', 'numeric', 'min:0'],
            'pieces_produced' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
