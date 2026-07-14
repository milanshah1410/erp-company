<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TdsTcsRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    public function rules(): array
    {
        $id = $this->route('tdsTcsRate')?->id;

        return [
            'section' => ['required', 'string', 'max:50', Rule::unique('tds_tcs_rates', 'section')->ignore($id)],
            'nature' => ['required', Rule::in(['tds', 'tcs'])],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'threshold' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
