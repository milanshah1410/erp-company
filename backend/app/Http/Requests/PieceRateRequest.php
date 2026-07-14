<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PieceRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        return [
            'rate_card_id' => ['required', 'integer', 'exists:rate_cards,id'],
            'operation' => ['required', 'string', 'max:255'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'sequence' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
